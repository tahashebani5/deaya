<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use Illuminate\Support\Carbon;

/**
 * The period a sales statistics board is asked for.
 *
 * Deliberately the same shape as {@see ProfitAndLossFilters}, down to the inclusive `to`: the two
 * reports are read in the same week about the same month, and a period that meant one thing on
 * one screen and something else on the other would make them disagree for a reason no reader
 * could see.
 *
 * **No period presets here, and that is a decision rather than an omission.** «اليوم» and «هذا
 * الأسبوع» are resolved by whoever is asking — the app knows what day it is in the shop's own
 * timezone, and the week it starts on is Saturday because the shop is in Libya. Naming the
 * presets in the API would put that calendar in two places and version it forever; a pair of
 * dates has no such problem.
 */
final readonly class SalesStatisticsFilters
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
            // Inclusive: a board for "today" should carry everything recognised today, not stop
            // at midnight.
            to: Carbon::parse((string) $validated['to'])->endOfDay(),
        );
    }
}
