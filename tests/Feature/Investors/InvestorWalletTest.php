<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealShare;
use App\Domain\Investor\Models\InvestorWalletEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The wallet: two pots, and the one door money leaves by.
 *
 * An investor's money is a single append-only ledger with no balance column anywhere, so every
 * figure below is a walk of the rows. What the tests pin is the shape of that walk — and, more
 * importantly, the two things it refuses.
 *
 * Arrange - Act - Assert throughout.
 */
class InvestorWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function treasurer(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInvestors->value,
            PermissionName::ManageInvestors->value,
            PermissionName::RecordInvestorMoney->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(array $headers, Investor $investor, array $payload): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/investors/{$investor->id}/wallet", $payload);
    }

    private function openDeal(Investor $investor): InvestorDeal
    {
        $deal = InvestorDeal::factory()->open()->create();

        InvestorDealShare::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'investor_id' => $investor->getKey(),
            'share_percent' => '100.0000',
        ]);

        return $deal;
    }

    /**
     * The investor's balances, with the per-deal list turned back into a lookup for readability.
     *
     * @return array{wallet: array<string, string>, deals: array<int, array<string, string>>}
     */
    private function balances(array $headers, Investor $investor): array
    {
        $payload = $this->withHeaders($headers)
            ->getJson("/api/v1/investors/{$investor->id}")
            ->json('data.balances');

        $deals = [];

        foreach ($payload['deals'] as $row) {
            $deals[(int) $row['investor_deal_id']] = $row;
        }

        return ['wallet' => $payload['wallet'], 'deals' => $deals];
    }

    // ─────────────────────────── the two pots ───────────────────────────

    public function test_money_deposited_sits_in_the_wallet_until_it_is_committed_to_a_deal(): void
    {
        // Arrange
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        // Act — 50,000 in, 30,000 of it committed.
        $this->record($headers, $investor, [
            'type' => WalletEntryType::Deposit->value,
            'amount' => '50000',
            'method' => 'cash',
        ])->assertCreated();

        $this->record($headers, $investor, [
            'type' => WalletEntryType::Allocation->value,
            'amount' => '30000',
            'investor_deal_id' => $deal->getKey(),
        ])->assertCreated();

        // Assert — «قد لا أستعمل كل أموال هذا المستثمر»: 20,000 is still his to commit
        // elsewhere or take back, and 30,000 is financing goods.
        $balances = $this->balances($headers, $investor);

        $this->assertSame('20000.00', $balances['wallet']['capital']);
        $this->assertSame('30000.00', $balances['deals'][$deal->getKey()]['capital']);
    }

    public function test_nobody_commits_money_that_is_not_in_the_wallet(): void
    {
        // Arrange — 10,000 deposited.
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        $this->record($headers, $investor, [
            'type' => WalletEntryType::Deposit->value,
            'amount' => '10000',
            'method' => 'cash',
        ])->assertCreated();

        // Act
        $response = $this->record($headers, $investor, [
            'type' => WalletEntryType::Allocation->value,
            'amount' => '15000',
            'investor_deal_id' => $deal->getKey(),
        ]);

        // Assert — the ceiling is read from the row held under the lock, which is what makes two
        // simultaneous commitments safe: without it both read 10,000, both pass, and 30,000
        // leaves a wallet holding 10,000.
        $response->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame('10000.00', $this->balances($headers, $investor)['wallet']['capital']);
    }

    public function test_profit_earned_by_a_running_deal_cannot_be_withdrawn(): void
    {
        // Arrange — the deal has earned him 1,500 and is still open.
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        $earning = new InvestorWalletEntry(['amount' => '1500.00', 'occurred_at' => now()]);
        $earning->investor_id = $investor->getKey();
        $earning->investor_deal_id = $deal->getKey();
        $earning->type = WalletEntryType::Profit;
        $earning->source_type = 'order';
        $earning->source_id = 1;
        $earning->save();

        // Act
        $response = $this->record($headers, $investor, [
            'type' => WalletEntryType::ProfitWithdrawal->value,
            'amount' => '1500',
            'method' => 'cash',
        ]);

        // Assert — «الربح يأتي تدريجياً ولا يُسحب إلا عند انتهاء الصفقة». Not a rule checked
        // here: profit only reaches the wallet through `profit_release`, which only closing a
        // deal writes, so a withdrawal has nothing to draw on until then.
        $response->assertStatus(422)->assertJsonValidationErrors('amount');

        $balances = $this->balances($headers, $investor);
        $this->assertSame('1500.00', $balances['deals'][$deal->getKey()]['profit']);
        $this->assertSame('0.00', $balances['wallet']['profit']);
    }

    public function test_closing_a_deal_returns_the_capital_and_releases_the_profit(): void
    {
        // Arrange — 30,000 committed, 1,500 earned, nothing left on the shelf.
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        $this->record($headers, $investor, [
            'type' => WalletEntryType::Deposit->value, 'amount' => '50000', 'method' => 'cash',
        ])->assertCreated();
        $this->record($headers, $investor, [
            'type' => WalletEntryType::Allocation->value, 'amount' => '30000',
            'investor_deal_id' => $deal->getKey(),
        ])->assertCreated();

        $earning = new InvestorWalletEntry(['amount' => '1500.00', 'occurred_at' => now()]);
        $earning->investor_id = $investor->getKey();
        $earning->investor_deal_id = $deal->getKey();
        $earning->type = WalletEntryType::Profit;
        $earning->source_type = 'order';
        $earning->source_id = 1;
        $earning->save();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/investor-deals/{$deal->id}/close")->assertOk();

        // Assert — the capital is back and spendable, the profit is now withdrawable, and the
        // deal itself holds nothing.
        $balances = $this->balances($headers, $investor);

        $this->assertSame('50000.00', $balances['wallet']['capital']);
        $this->assertSame('1500.00', $balances['wallet']['profit']);
        $this->assertSame('0.00', $balances['deals'][$deal->getKey()]['capital']);
        $this->assertSame('0.00', $balances['deals'][$deal->getKey()]['profit']);
        $this->assertSame(DealStatus::Closed, $deal->refresh()->status);

        // And now — and only now — he can be paid.
        $this->record($headers, $investor, [
            'type' => WalletEntryType::ProfitWithdrawal->value, 'amount' => '1500', 'method' => 'cash',
        ])->assertCreated();

        $this->assertSame('0.00', $this->balances($headers, $investor)['wallet']['profit']);
    }

    public function test_a_losing_deal_takes_the_loss_out_of_that_deals_capital_and_nothing_else(): void
    {
        // Arrange — 30,000 committed out of 50,000, and the deal ends 6,500 down.
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        $this->record($headers, $investor, [
            'type' => WalletEntryType::Deposit->value, 'amount' => '50000', 'method' => 'cash',
        ])->assertCreated();
        $this->record($headers, $investor, [
            'type' => WalletEntryType::Allocation->value, 'amount' => '30000',
            'investor_deal_id' => $deal->getKey(),
        ])->assertCreated();

        $loss = new InvestorWalletEntry(['amount' => '6500.00', 'occurred_at' => now()]);
        $loss->investor_id = $investor->getKey();
        $loss->investor_deal_id = $deal->getKey();
        $loss->type = WalletEntryType::Loss;
        $loss->source_type = 'order';
        $loss->source_id = 1;
        $loss->save();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/investor-deals/{$deal->id}/close")->assertOk();

        // Assert — 30,000 − 6,500 = 23,500 returns, so he has 43,500 of his original 50,000.
        //
        // Every amount in this ledger is positive by constraint, so there was no negative release
        // to hand back with: the loss is written as its own row against the capital he had **in
        // this deal**, and no other deal's money and no earned profit is touched.
        $balances = $this->balances($headers, $investor);

        $this->assertSame('43500.00', $balances['wallet']['capital']);
        $this->assertSame('0.00', $balances['deals'][$deal->getKey()]['capital']);
        $this->assertSame('0.00', $balances['deals'][$deal->getKey()]['profit']);

        $this->assertDatabaseHas('investor_wallet_entries', [
            'investor_deal_id' => $deal->getKey(),
            'type' => WalletEntryType::CapitalWritedown->value,
            'amount' => '6500.00',
        ]);
    }

    public function test_a_loss_deeper_than_his_capital_is_the_companys_and_says_so(): void
    {
        // Arrange — 10,000 in the deal, 15,000 lost.
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        $this->record($headers, $investor, [
            'type' => WalletEntryType::Deposit->value, 'amount' => '10000', 'method' => 'cash',
        ])->assertCreated();
        $this->record($headers, $investor, [
            'type' => WalletEntryType::Allocation->value, 'amount' => '10000',
            'investor_deal_id' => $deal->getKey(),
        ])->assertCreated();

        $loss = new InvestorWalletEntry(['amount' => '15000.00', 'occurred_at' => now()]);
        $loss->investor_id = $investor->getKey();
        $loss->investor_deal_id = $deal->getKey();
        $loss->type = WalletEntryType::Loss;
        $loss->source_type = 'order';
        $loss->source_id = 1;
        $loss->save();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/investor-deals/{$deal->id}/close")->assertOk();

        // Assert — his 10,000 is gone and the remaining 5,000 is the company's, on a line of its
        // own. Nothing in the arrangement makes him owe more than he put in, and a difference
        // with no name would be exactly what an investor disputes.
        $this->assertSame('0.00', $this->balances($headers, $investor)['wallet']['capital']);

        $this->assertDatabaseHas('investor_wallet_entries', [
            'investor_deal_id' => $deal->getKey(),
            'type' => WalletEntryType::LossAbsorbedByCompany->value,
            'amount' => '5000.00',
        ]);
    }

    public function test_an_earning_cannot_be_typed_in_by_hand(): void
    {
        // Arrange
        $investor = Investor::factory()->create();
        $headers = $this->treasurer();
        $deal = $this->openDeal($investor);

        // Act
        $response = $this->record($headers, $investor, [
            'type' => WalletEntryType::Profit->value,
            'amount' => '9999',
            'investor_deal_id' => $deal->getKey(),
        ]);

        // Assert — a profit is written by the order that produced it and by nothing else, or the
        // deal screen and the orders behind it could say two different things.
        $response->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame(0, InvestorWalletEntry::query()->count());
    }
}
