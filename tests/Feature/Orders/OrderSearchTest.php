<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\OrderSearchKind;
use App\Domain\Order\Queries\OrderSearchTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * One search box, three questions.
 *
 * Staff look an order up by its number, by the customer's code, or by their phone — and they
 * should not have to say which first. The shape of what they type is enough to tell, and this
 * file is where that claim is held to account.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderSearchTest extends TestCase
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
    private function viewer(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────── classifying what was typed ───────────────────────

    /**
     * @return array<string, array{0: string, 1: OrderSearchKind, 2: string}>
     */
    public static function terms(): array
    {
        return [
            'a full Libyan mobile is a phone' => ['0912345678', OrderSearchKind::Phone, '0912345678'],
            'so is a half-typed one' => ['0912', OrderSearchKind::Phone, '0912'],
            'every Libyan prefix, not just 091' => ['0945556677', OrderSearchKind::Phone, '0945556677'],
            'spaces and dashes are punctuation' => ['091-234 5678', OrderSearchKind::Phone, '0912345678'],
            'Arabic-Indic digits are digits' => ['٠٩١٢٣٤٥٦٧٨', OrderSearchKind::Phone, '0912345678'],
            'a plain number is an order' => ['52', OrderSearchKind::OrderCode, '52'],
            'a one-digit order number too' => ['7', OrderSearchKind::OrderCode, '7'],
            'a number starting with a zero that is not 09' => ['052', OrderSearchKind::OrderCode, '052'],
            'a letter then digits is a customer code' => ['C7', OrderSearchKind::CustomerCode, 'C7'],
            'and it does not need the shift key' => ['c7', OrderSearchKind::CustomerCode, 'C7'],
            'anything else is a name' => ['أحمد', OrderSearchKind::Name, 'أحمد'],
            'including a name with a number in it' => ['محل 5', OrderSearchKind::Name, 'محل 5'],
        ];
    }

    #[DataProvider('terms')]
    public function test_the_shape_of_the_term_decides_what_it_means(
        string $typed,
        OrderSearchKind $kind,
        string $value,
    ): void {
        // Act
        $term = OrderSearchTerm::from($typed);

        // Assert
        $this->assertSame($kind, $term->kind, "«{$typed}» should be read as {$kind->value}");
        $this->assertSame($value, $term->value);
    }

    // ─────────────────────────── through the endpoint ───────────────────────────

    public function test_a_phone_number_finds_that_customers_orders(): void
    {
        // Arrange
        $wanted = Customer::factory()->create(['phone' => '0912345678']);
        $other = Customer::factory()->create(['phone' => '0923334444']);
        Order::factory()->forCustomer($wanted)->create();
        Order::factory()->forCustomer($other)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?search=0912345678');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_id', $wanted->id);
    }

    public function test_a_half_typed_phone_number_narrows_rather_than_failing(): void
    {
        // Arrange
        $wanted = Customer::factory()->create(['phone' => '0912345678']);
        $other = Customer::factory()->create(['phone' => '0955556666']);
        Order::factory()->forCustomer($wanted)->create();
        Order::factory()->forCustomer($other)->create();
        $headers = $this->viewer();

        // Act — as much of it as they remember.
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?search=0912');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_order_number_finds_exactly_that_order(): void
    {
        // Arrange — 5 and 52 both exist, and both start with the digit typed.
        $orders = Order::factory()->count(60)->create();
        $wanted = $orders->firstWhere('code', '5') ?? $orders->first();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders?search={$wanted->code}");

        // Assert — «طلبية رقم ٥» means that one; returning 5, 52 and 53 beside it is noise.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $wanted->code);
    }

    public function test_a_customer_code_finds_everything_they_have_ordered(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->count(3)->forCustomer($customer)->create();
        Order::factory()->count(2)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders?search={$customer->code}");

        // Assert
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_customer_code_is_found_however_it_was_typed(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->create();
        $headers = $this->viewer();

        // Act — lowercase, as somebody typing quickly would.
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?search='.strtolower($customer->code));

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_name_still_finds_orders(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['name' => 'سوق المدينة']);
        Order::factory()->forCustomer($customer)->create();
        Order::factory()->create();
        $headers = $this->viewer();

        // Act
        // Encoded, as a client would send it: an Arabic term dropped raw into a query string
        // is not valid UTF-8 by the time the framework parses it.
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?search='.urlencode('المدينة'));

        // Assert — not one of the three the business named, but taking it away would break a
        // search that works today for somebody who only knows the customer by name.
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_phone_number_is_never_read_as_an_order_number(): void
    {
        // Arrange — an order numbered 9 exists; searching 09... must not reach it.
        Order::factory()->count(12)->create();
        Customer::factory()->create(['phone' => '0912345678']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?search=091');

        // Assert — this is the whole reason `09` is the discriminator: order numbers are
        // allocated from 1 and never carry a leading zero.
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_search_that_matches_nothing_is_an_empty_page_not_an_error(): void
    {
        // Arrange
        Order::factory()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?search=C999999');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_the_search_narrows_the_queue_rather_than_replacing_it(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->status(OrderStatus::Printing)->create();
        Order::factory()->forCustomer($customer)->status(OrderStatus::Delivered)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/orders?search={$customer->code}&status=printing");

        // Assert — the two filters are an AND; a search that escaped its grouping would return
        // the delivered one too.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'printing');
    }

    // ─────────────────────── narrowing by day ───────────────────────

    public function test_a_day_asked_for_is_the_shop_s_day_not_the_server_s(): void
    {
        // Arrange — 00:30 in Tripoli on the 15th is 22:30 UTC on the *14th*. Both the home
        // screen's count and this list have to agree that it belongs to the 15th, or a card
        // opens a list that contradicts the number on it.
        $business = new \DateTimeZone((string) config('app.business_timezone'));
        $justAfterMidnight = Carbon::create(2026, 8, 15, 0, 30, 0, $business);

        $today = Order::factory()->create(['placed_at' => $justAfterMidnight->copy()->utc()]);
        Order::factory()->create(['placed_at' => $justAfterMidnight->copy()->subHours(3)->utc()]);

        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?from=2026-08-15&to=2026-08-15');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $today->id);
    }
}
