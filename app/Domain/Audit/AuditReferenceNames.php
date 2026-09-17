<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Contracts\HasAuditDisplayName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The names behind the foreign keys on one page of history.
 *
 * `customer_id: 12` is a number nobody in the workshop can read, and the only way to turn it
 * into «مطبعة النور» is to go and look. That is the one part of value translation that costs a
 * query — enums and dates are pure arithmetic — so it is done **once for the whole page** and
 * not once per line: fifteen entries naming customers is one `where id in (…)`, not fifteen
 * round trips. `AuditValueLabelsTest` counts the queries and fails if that ever slips.
 *
 * Hence the shape: collect what the page wants, resolve it in one pass, then hand the finished
 * map to each entry. An entry left holding {@see empty()} still renders — it prints the raw id,
 * exactly as the screen did before any of this existed.
 */
final class AuditReferenceNames
{
    /**
     * Where a readable name lives, in the order we would read them out.
     *
     * `code` last and not first: an order has only a code, but a vendor has both, and «المورد:
     * V-014» is a worse answer than the vendor's actual name. A record with none of these is
     * left as its id — see {@see HasAuditDisplayName} for the ones that need to say otherwise.
     *
     * @var list<string>
     */
    private const NAME_COLUMNS = ['name', 'label', 'title', 'code'];

    /**
     * @param  array<class-string<Model>, array<string, string>>  $names  model => id => name
     */
    private function __construct(private readonly array $names) {}

    /**
     * For the paths that resolve nothing — a single entry rendered outside a collection, or a
     * page that turned out to reference no other record at all.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * One query per kind of record, and none at all for a kind nobody asked about.
     *
     * Soft-deleted records are read too, deliberately. The trail outliving its subjects is the
     * whole reason for keeping one; a history that forgets the customer's name the moment the
     * customer is deleted has lost the part somebody would come looking for.
     *
     * @param  array<class-string<Model>, list<int|string>>  $wanted  model => ids
     */
    public static function resolve(array $wanted): self
    {
        $names = [];

        foreach ($wanted as $class => $ids) {
            $ids = array_values(array_unique(array_filter(
                $ids,
                fn (mixed $id) => $id !== null && $id !== '',
            )));

            if ($ids === [] || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $query = $class::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }

            /** @var Model $record */
            foreach ($query->whereKey($ids)->get() as $record) {
                $name = self::displayName($record);

                if ($name !== null) {
                    $names[$class][(string) $record->getKey()] = $name;
                }
            }
        }

        return new self($names);
    }

    /**
     * Null for a record that is gone entirely, or that has no readable name.
     *
     * Both cases fall back to the raw id at the call site rather than to an invented «غير
     * معروف», which would look like data somebody entered.
     *
     * @param  class-string<Model>  $class
     */
    public function nameFor(string $class, int|string $id): ?string
    {
        return $this->names[$class][(string) $id] ?? null;
    }

    private static function displayName(Model $record): ?string
    {
        if ($record instanceof HasAuditDisplayName) {
            $name = trim($record->auditDisplayName());

            return $name === '' ? null : $name;
        }

        // Read from the loaded attributes rather than through `getAttribute()`, which throws
        // under `preventAccessingMissingAttributes()` — and an order simply has no `name`.
        // Probing for a column is exactly the case that guard is meant to catch, so the probe
        // must not go through it.
        $attributes = $record->getAttributes();

        foreach (self::NAME_COLUMNS as $column) {
            $value = $attributes[$column] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
