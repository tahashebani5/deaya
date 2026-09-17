<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Api\V1\Middleware\DeshapeArabicInput;
use App\Support\ArabicText;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Folds pre-shaped Arabic that is already in the database back into letters.
 *
 * Written for the rows that predate
 * {@see DeshapeArabicInput}: everything typed from now on is
 * folded at the door, and everything typed before it is still sitting in its table exactly as the
 * keyboard sent it. One such character — a `ﻱ` (U+FEF1) in a customer's name — is what stopped
 * order 1228's invoice from being drawn. {@see ArabicText} for what the fold is and why.
 *
 * **The columns are read off the database rather than listed here.** A list would be a second
 * copy of the schema, and the copy is what goes stale: the column added next month is exactly the
 * one nobody would remember to add to it. Every text column of every base table is asked the same
 * question — «does anything in you carry a presentation form?» — and almost all of them answer no
 * in a single indexed-free scan of a small table.
 *
 * **What is deliberately left alone:**
 *
 *   - **The audit trail.** History records what happened, including what was typed. Rewriting it
 *     to read better makes it a worse record, and nothing ever draws it into a document.
 *   - **Anything that names a thing rather than says it** — a path, a URL, a slug, a token, a
 *     password. A stored path is matched against a real file on a real disk; folding it would
 *     leave the row pointing at nothing.
 *   - **Framework tables** — migrations, jobs, sessions, caches. They hold no Arabic prose.
 *   - **`json` columns.** Their text is a payload with a shape, and a blind fold over it is a
 *     rewrite of data this repair has no business understanding.
 *
 * **Rows that need nothing are not written to**, so `updated_at` still says when a person last
 * changed the record rather than when a cleanup ran over it. Re-running it changes nothing.
 */
class DeshapeStoredText extends Command
{
    use ConfirmableTrait;

    protected $signature = 'text:deshape
                            {--dry-run : Report what would change and write nothing}
                            {--force : Skip the confirmation prompt outside local}';

    protected $description = 'Fold pre-shaped Arabic in stored text back into the letters it is made of';

    /**
     * Tables whose contents are not the shop's own prose.
     *
     * @var list<string>
     */
    private const SKIP_TABLES = [
        'activity_log',
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'cache',
        'cache_locks',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /**
     * Columns that name something rather than say something.
     *
     * @var list<string>
     */
    private const SKIP_COLUMNS = [
        'password',
        'remember_token',
        'token',
        'secret',
        'path',
        'disk',
        'url',
        'slug',
        'hash',
        'mime_type',
        'file_name',
        'filename',
    ];

    public function handle(): int
    {
        // This rewrites stored data. On anything but local it asks first, and `--force` is the
        // deliberate answer rather than the default.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $pattern = ArabicText::sqlPattern();

        /** @var list<array{string, string, int}> $changes */
        $changes = [];
        $rowsTouched = 0;

        foreach ($this->textColumns() as [$table, $column]) {
            /** @var list<string> $values */
            $values = DB::table($table)
                ->whereRaw(sprintf('%s ~ ?', $this->quote($column)), [$pattern])
                ->distinct()
                ->pluck($column)
                ->all();

            foreach ($values as $value) {
                $folded = ArabicText::deshape((string) $value);

                if ($folded === $value) {
                    continue;
                }

                // Matched on the old value rather than on a primary key: every row carrying the
                // same shaped spelling is the same mistake, a pivot table has no `id` to hold on
                // to, and one statement per distinct spelling is far fewer than one per row.
                $rows = $dryRun
                    ? DB::table($table)->where($column, $value)->count()
                    : DB::table($table)->where($column, $value)->update([$column => $folded]);

                $changes[] = [$table.'.'.$column, $value.' → '.$folded, $rows];
                $rowsTouched += $rows;
            }
        }

        if ($changes === []) {
            $this->info('No stored text carries pre-shaped Arabic. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Column', 'Value', 'Rows'], $changes);

        $this->info($dryRun
            ? sprintf('%d row(s) would be folded. Nothing was written.', $rowsTouched)
            : sprintf('%d row(s) folded.', $rowsTouched));

        return self::SUCCESS;
    }

    /**
     * Every text column of every base table, minus what is deliberately left alone.
     *
     * @return list<array{string, string}>
     */
    private function textColumns(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.table_name, c.column_name
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = current_schema()
              AND t.table_type = 'BASE TABLE'
              AND c.data_type IN ('character varying', 'text', 'character')
              AND c.is_generated = 'NEVER'
            ORDER BY c.table_name, c.column_name
        SQL);

        $columns = [];

        foreach ($rows as $row) {
            $table = (string) $row->table_name;
            $column = (string) $row->column_name;

            if (in_array($table, self::SKIP_TABLES, true)) {
                continue;
            }

            // Telescope and Pulse are debugging furniture when they are installed at all.
            if (str_starts_with($table, 'telescope_') || str_starts_with($table, 'pulse_')) {
                continue;
            }

            if (in_array($column, self::SKIP_COLUMNS, true)) {
                continue;
            }

            $columns[] = [$table, $column];
        }

        return $columns;
    }

    /**
     * A column name as PostgreSQL wants it in a raw fragment.
     */
    private function quote(string $column): string
    {
        return '"'.str_replace('"', '""', $column).'"';
    }
}
