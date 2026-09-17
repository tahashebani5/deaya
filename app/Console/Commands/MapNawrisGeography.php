<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Carrier\Actions\MatchNawrisGeography;
use Illuminate\Console\Command;

/**
 * Fills in `cities.nawris_government_id` and `regions.nawris_area_id` from their own lists.
 *
 * **A deploy step before the first parcel, not a migration.** The ids are theirs and are read over
 * HTTP, so they cannot be seeded; and an unmapped city refuses dispatch by name, so the «إرسال
 * للنورس» button fails for that destination until this has run.
 *
 * Idempotent, and safe to run again: {@see MatchNawrisGeography} never overwrites a mapping that
 * is already there. Run it with `--dry-run` first — the same report, nothing written.
 *
 * **Nothing is caught here.** A missing key and a carrier that did not answer both throw domain
 * exceptions that already say what went wrong in Arabic, and artisan prints them; catching them to
 * reprint the same sentence would only cost the stack trace that says where it happened.
 */
class MapNawrisGeography extends Command
{
    protected $signature = 'nawris:map-geography {--dry-run : Report the matches without writing them}';

    protected $description = 'Match our cities and regions to Nawris governments and areas by name';

    public function handle(MatchNawrisGeography $match): int
    {
        $preview = (bool) $this->option('dry-run');

        $report = $match(apply: ! $preview);

        $this->line($preview ? 'معاينة — لم يُكتب شيء:' : 'تم التحديث:');
        $this->line("  مدن طوبقت: {$report->matchedCities}   مناطق طوبقت: {$report->matchedRegions}");
        $this->line("  مدن مربوطة من قبل: {$report->alreadyMappedCities}");

        // **Printed before the to-do list, and deliberately loud.** These are the run's guesses:
        // names matched a letter or two away rather than exactly. An unread approximate match on
        // a city is a parcel in the wrong town, so it goes where somebody scanning the output
        // cannot miss it — above the list they were waiting for.
        if ($report->approximateMatches !== []) {
            $this->newLine();
            $this->warn('مطابقات تقريبية — راجعها ('.count($report->approximateMatches).'):');
            $this->line('  اسمنا ← اسمهم. صحّح أي سطر خاطئ يدوياً، فهذه تخمينات لا حقائق.');

            foreach ($report->approximateMatches as $pair) {
                $this->line('  - '.$pair);
            }
        }

        // The half that is a to-do list rather than a score.
        foreach ([
            'مدن لا مقابل لها عندهم' => $report->unmatchedCities,
            'مناطق لا مقابل لها عندهم' => $report->unmatchedRegions,
        ] as $heading => $names) {
            if ($names === []) {
                continue;
            }

            $this->newLine();
            $this->warn($heading.' ('.count($names).'):');

            foreach ($names as $name) {
                $this->line('  - '.$name);
            }
        }

        return self::SUCCESS;
    }
}
