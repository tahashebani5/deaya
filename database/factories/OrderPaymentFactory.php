<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    /** @var class-string<OrderPayment> */
    protected $model = OrderPayment::class;

    /**
     * A cash payment by default — the commonest entry, and the only type that needs no other
     * row to exist first.
     *
     * **Building an entry this way does not move `orders.paid_amount`**, which the real write
     * path would never do. That is right for tests about reading the ledger and wrong for tests
     * about the balance; those go through the endpoints.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => OrderPaymentType::Payment,
            'amount' => '100.00',
            'method' => PaymentMethod::Cash,
            'reference' => null,
            'paid_at' => now(),
            'notes' => null,
            'reverses_payment_id' => null,
            'recorded_by' => User::factory(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => ['order_id' => $order->getKey()]);
    }

    public function amount(string $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    public function method(PaymentMethod $method): static
    {
        return $this->state(fn () => ['method' => $method]);
    }

    /**
     * A transfer with its receipt on file.
     *
     * The two go together and are not offered apart, because the database refuses a transfer
     * without one — a factory that could build the illegal half would only ever produce a
     * confusing constraint violation in an unrelated test.
     */
    public function bankTransfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => PaymentMethod::BankTransfer,
            'reference' => 'TRF-'.($attributes['order_id'] ?? 0),
            'receipt_disk' => 'local',
            'receipt_path' => 'payment-receipts/factory/'.fake()->uuid().'.pdf',
            'receipt_original_filename' => 'receipt.pdf',
            'receipt_size_bytes' => 24_576,
            'receipt_checksum' => hash('sha256', fake()->uuid()),
        ]);
    }

    /** Money handed back to the customer. */
    public function refund(): static
    {
        return $this->state(fn () => ['type' => OrderPaymentType::Refund]);
    }

    /**
     * An entry that undoes another.
     *
     * The method is blanked deliberately: a reversal moved no money, so it names none — and the
     * table's own CHECK refuses a row that says otherwise.
     */
    public function reversing(OrderPayment $payment): static
    {
        return $this->state(fn () => [
            'order_id' => $payment->order_id,
            'type' => OrderPaymentType::Reversal,
            'amount' => $payment->amount,
            'method' => null,
            'reverses_payment_id' => $payment->getKey(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['recorded_by' => $user->getKey()]);
    }
}
