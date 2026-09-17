<?php

declare(strict_types=1);

namespace App\Domain\Shortage\DTOs;

use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Shortage\Enums\SupplyKind;
use App\Domain\Shortage\Support\Money;
use Illuminate\Http\UploadedFile;

/**
 * One go at closing a shortage, as the employee filled it in.
 *
 * **The three that are not optional are the three the brief names:** the quantity that came back,
 * what was paid for it, and how. They are typed non-nullable here rather than validated into
 * shape later, so a console command or an importer meets the same rule the form does — and the
 * CHECK on `shortage_supplies` meets it a third time.
 *
 * `kind` is absent: this DTO only ever describes a purchase. The other kind is written by the
 * sync, straight from an order line, and never arrives as a request — see {@see SupplyKind}.
 */
final readonly class ShortageSupplyData
{
    public function __construct(
        public string $quantity,
        public string $amount,
        public PaymentMethod $method,
        public string $occurredOn,

        /**
         * Where the goods landed.
         *
         * **Required for anything the warehouse can hold**, and refused for anything it cannot —
         * the domain decides which from the shortage, not from this field being present. See
         * `Shortage::isStockable()`.
         */
        public ?int $warehouseId = null,

        public ?string $reference = null,
        public ?string $notes = null,

        /**
         * The paper the goods were bought with — a PDF, or the photograph that actually arrives.
         *
         * **Never required**, unlike a customer's payment: see the request's own note.
         */
        public ?UploadedFile $receipt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            // A quantity is three places and a price is two, the same split `order_items` makes:
            // a per-kilo sack comes back in fractions, and what was handed over is money.
            quantity: bcadd((string) $validated['quantity'], '0', 3),
            amount: Money::normalize($validated['amount']),
            method: PaymentMethod::from((string) $validated['method']),
            // Defaulted by the request rather than here, so a caller that means «اليوم» says so
            // once. What is stored is when the goods were got, not when somebody typed it in.
            occurredOn: (string) $validated['occurred_on'],
            warehouseId: isset($validated['warehouse_id']) && $validated['warehouse_id'] !== ''
                ? (int) $validated['warehouse_id']
                : null,
            reference: isset($validated['reference']) && trim((string) $validated['reference']) !== ''
                ? trim((string) $validated['reference'])
                : null,
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== ''
                ? trim((string) $validated['notes'])
                : null,
            receipt: ($validated['receipt'] ?? null) instanceof UploadedFile
                ? $validated['receipt']
                : null,
        );
    }
}
