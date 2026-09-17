<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * One entry a human is asking us to write, in the shape the ledger stores it.
 *
 * **No `type` field.** Which of the three kinds of entry this becomes is decided by *which
 * action ran*, not by a value in the payload — so there is no arrangement of request fields that
 * turns a payment into a reversal, and no endpoint that needs a discriminator validated.
 *
 * `recordedBy` is absent for the same reason it is absent from `StockMovementData`: it comes
 * from the authenticated user at the boundary, so nothing a caller sends can attribute a
 * collection to a colleague.
 */
final readonly class OrderPaymentData
{
    private function __construct(
        /** Always positive, normalised to two places. The direction is the entry type's. */
        public string $amount,
        public PaymentMethod $method,
        public ?string $reference,
        /** When the money moved — not when this was typed in. Defaults to now. */
        public Carbon $paidAt,
        public ?string $notes,
        /**
         * The scanned receipt (الواصل) — a PDF, or the photograph that actually arrives.
         *
         * Required when the method says so — see {@see PaymentMethod::requiresReceipt()} — and
         * accepted on any other method, because somebody who has the paper should never be told
         * we have nowhere to put it.
         */
        public ?UploadedFile $receipt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $receipt = $validated['receipt'] ?? null;

        return new self(
            amount: self::amount($validated['amount']),
            method: PaymentMethod::from((string) $validated['method']),
            reference: self::textOrNull($validated['reference'] ?? null),
            paidAt: self::paidAt($validated['paid_at'] ?? null),
            notes: self::textOrNull($validated['notes'] ?? null),
            receipt: $receipt instanceof UploadedFile ? $receipt : null,
        );
    }

    /**
     * Cast through a string and never left as a float.
     *
     * The same rule the rest of this domain's money follows: this number is compared against a
     * remaining balance and added to a running total, and a total that picks up binary drift is
     * a discrepancy nobody will ever be able to explain to a customer.
     *
     * The cast itself lives in {@see Money::normalize()}, so the write-off endpoint — which has
     * no DTO of its own, having neither a method nor a date to carry — reads an amount off a
     * request exactly the way this does.
     */
    private static function amount(mixed $value): string
    {
        return Money::normalize($value);
    }

    /**
     * A back-dated entry is ordinary — a deposit taken on Thursday evening is entered on
     * Saturday morning — so a date is accepted. A *future* one is refused by the request rules,
     * because money that has not moved yet is not a ledger entry.
     */
    private static function paidAt(mixed $value): Carbon
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? Carbon::parse($text) : Carbon::now();
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
