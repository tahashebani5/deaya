<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use Illuminate\Support\Carbon;

final readonly class ProfitAndLossFilters
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            from: Carbon::parse((string) $validated['from'])->startOfDay(),
            // Inclusive: a report for "today" should carry everything recognised today, not stop
            // at midnight.
            to: Carbon::parse((string) $validated['to'])->endOfDay(),
        );
    }
}
