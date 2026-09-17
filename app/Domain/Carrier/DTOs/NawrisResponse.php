<?php

declare(strict_types=1);

namespace App\Domain\Carrier\DTOs;

/**
 * One answer from Nawris, after the shape has been made uniform.
 *
 * **Their responses are not uniform, and every quirk is absorbed here rather than by callers.**
 * The payload arrives under `result` on some endpoints and `feed` on others; a success is
 * sometimes an object and sometimes just `success == 1`. An action that had to know which of
 * those it was dealing with would be an action that breaks when they change one.
 *
 * Everything is read defensively and typed on the way out: `code` and `bar_code` come back as
 * strings or integers depending on the endpoint, and a parcel keyed on `3702994` and one keyed on
 * `'3702994'` are the same parcel.
 */
final readonly class NawrisResponse
{
    /**
     * @param  array<string, mixed>  $data  The unwrapped payload — `result` or `feed`, whichever came.
     * @param  array<string, mixed>  $raw  The whole body, for the log and for anything not yet named.
     */
    public function __construct(
        public array $data,
        public array $raw,
    ) {}

    /** Their handle on the parcel. Null when the endpoint does not return one — delete, cancel. */
    public function code(): ?string
    {
        return $this->string('code');
    }

    /** What gets physically scanned at handover, and the last webhook fallback. */
    public function barCode(): ?string
    {
        return $this->string('bar_code');
    }

    public function invoiceNumber(): ?string
    {
        return $this->string('invoice_number');
    }

    /**
     * A value out of the payload as a trimmed string, or null when it is absent or empty.
     *
     * Casts through string deliberately: an id that arrives as an integer on one endpoint and a
     * string on another must not produce two different parcels.
     */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * The payload as a list, for the two lookups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return array_values(array_filter($this->data, 'is_array'));
    }
}
