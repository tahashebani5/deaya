<?php

declare(strict_types=1);

namespace App\Domain\Carrier\DTOs;

use App\Domain\Carrier\Enums\NawrisStatusCode;

/**
 * One inbound webhook body, read defensively.
 *
 * **Nine fields are meaningful and only one drives state.** Everything is optional, because the
 * contract says so and because a payload we refuse to parse is a delivery we never hear about: an
 * unmatched or unreadable event still gets stored, still gets a row, and can still be replayed.
 *
 * `order_price` **arrives as a string in practice**, so it is normalised here rather than in the
 * comparison — a numeric comparison decided by PHP's string rules is a coin toss on money.
 */
final readonly class NawrisWebhookPayload
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $raw,
        public ?string $reference,
        public ?string $code,
        public ?int $statusCode,
        public ?string $statusText,
        public ?string $collectedAmount,
        public ?string $returnReason,
        public ?string $delayReason,
        public ?string $captainName,
        public ?string $captainPhone,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            raw: $body,
            reference: self::text($body['remote_order_id'] ?? null),
            code: self::text($body['order_code'] ?? null),
            statusCode: isset($body['to_status_code']) && is_numeric($body['to_status_code'])
                ? (int) $body['to_status_code']
                : null,
            statusText: self::text($body['to_status_text'] ?? null),
            collectedAmount: self::money($body['order_price'] ?? null),
            returnReason: self::text($body['return_reason'] ?? null),
            delayReason: self::text($body['delay_reason'] ?? null),
            captainName: self::text($body['captain_name'] ?? null),
            captainPhone: self::text($body['captain_phone'] ?? null),
        );
    }

    public function status(): ?NawrisStatusCode
    {
        return NawrisStatusCode::tryFromCode($this->statusCode);
    }

    /**
     * A stable hash of the fields that make this event *this* event.
     *
     * **The raw body is deliberately not hashed.** Their payload may carry a timestamp or a field
     * we do not read, and any of those would make every duplicate look distinct — which is exactly
     * the failure this is meant to prevent. Hashing the meaningful fields means a genuine re-send
     * of the same news collides, as it should.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->reference ?? '',
            $this->code ?? '',
            (string) ($this->statusCode ?? ''),
            $this->collectedAmount ?? '',
            $this->returnReason ?? '',
        ]));
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** Normalised to a decimal string, so nothing downstream compares money as text. */
    private static function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
