<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Audit\AuditReferenceNames;
use App\Domain\Audit\AuditValueLabels;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Audit\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of history, with the names behind its foreign keys already looked up.
 *
 * Everything else an entry needs to speak Arabic is arithmetic — an enum knows its own label.
 * Foreign keys are the exception: `customer_id: 12` can only become «مطبعة النور» by going to
 * look, and an entry that went looking on its own would turn a page of fifteen into forty
 * queries.
 *
 * So the lookup is hoisted here, where the whole page is visible at once: collect every
 * (model, id) the page mentions, resolve them in one query per kind, and hand the finished map
 * down to each entry.
 *
 * **Primed in the constructor, not in `toArray()`**, and that is load-bearing: paginated
 * responses are serialised from `$collection->collection` — the resources themselves — so this
 * collection's own `toArray()` is never called on that path. Priming on construction is the one
 * point both paths pass through.
 */
class ActivityLogCollection extends ResourceCollection
{
    /** @var class-string<ActivityLogResource> */
    public $collects = ActivityLogResource::class;

    /**
     * @param  mixed  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);

        $names = AuditReferenceNames::resolve($this->referencedRecords());

        $this->collection->each(
            fn (ActivityLogResource $entry) => $entry->withReferenceNames($names),
        );
    }

    /**
     * Every other record this page points at, grouped by kind so each kind costs one query.
     *
     * @return array<class-string<Model>, list<int|string>>
     */
    private function referencedRecords(): array
    {
        $wanted = [];

        foreach ($this->collection as $entry) {
            $log = $entry->resource;

            if (! $log instanceof ActivityLog) {
                continue;
            }

            $subject = AuditSubject::tryFrom((string) $log->subject_type);
            $halves = self::halvesOf($log);
            $columns = array_values(array_unique(array_merge(
                ...array_map(array_keys(...), $halves),
            )));

            foreach (AuditValueLabels::referencesFor($subject, $columns) as $column => $class) {
                foreach ($halves as $half) {
                    $id = $half[$column] ?? null;

                    if (is_int($id) || (is_string($id) && $id !== '')) {
                        $wanted[$class][] = $id;
                    }
                }
            }
        }

        return $wanted;
    }

    /**
     * The before and after of one entry, as plain arrays.
     *
     * Both halves, never just `attributes`: a deletion records only `old`, and the customer it
     * named needs a name as much as a creation's does.
     *
     * @return list<array<string, mixed>>
     */
    private static function halvesOf(ActivityLog $log): array
    {
        $changes = $log->attribute_changes;

        if ($changes === null) {
            return [];
        }

        return array_values(array_filter(
            [$changes->get('old'), $changes->get('attributes')],
            is_array(...),
        ));
    }
}
