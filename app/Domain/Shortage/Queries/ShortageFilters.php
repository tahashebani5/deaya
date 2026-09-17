<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Queries;

use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;

/**
 * What the shortages list is being asked for.
 *
 * **`includeArchivedOrders` is not a filter the user sets.** It is the reader's grant, carried
 * down from the controller, and it is here rather than in the query for the reason
 * ORDER-DELETE-AND-ARCHIVE §٦ gives about `onlyTrashed()`: two queries seed `Shortage::query()`
 * separately — the list and the status counts — and a rule living in one of them makes the two
 * contradict each other in front of the reader. A chip row that counts rows the list will not
 * show is how an archive leaks without anybody opening it.
 */
final readonly class ShortageFilters
{
    /**
     * @param  list<ShortageStatus>|null  $statuses  more than one, because the queues people ask
     *                                               for are groups: «كل ما لم يُغلق» is «جديد»
     *                                               and «جاري البحث» and «غير متوفر» together,
     *                                               and a screen that had to call three times to
     *                                               draw one number would draw the wrong one.
     */
    public function __construct(
        public ?array $statuses = null,
        public ?int $assignedToUserId = null,
        /** «غير مُسنَد» — a real question, and not the same as "no assignee filter". */
        public bool $unassignedOnly = false,
        public ?int $productId = null,
        public ?ShortageSource $source = null,
        public ?int $orderId = null,
        public ?int $customerId = null,
        /** Matches the shortage's own name, its code, or the order's code. */
        public ?string $search = null,
        /** The reader's `orders.archive.view` grant — see the class docblock. */
        public bool $includeArchivedOrders = false,
    ) {}

    /**
     * @param  array<string, mixed>  $query  Validated query-string values.
     */
    public static function fromArray(array $query, bool $includeArchivedOrders = false): self
    {
        $assignee = $query['assigned_to'] ?? null;

        return new self(
            statuses: self::statuses($query),
            // `assigned_to=me` is resolved to an id by the controller, which is the only layer
            // that knows who is asking. What arrives here is always an id or «none».
            assignedToUserId: $assignee !== null && $assignee !== '' && $assignee !== 'none'
                ? (int) $assignee
                : null,
            unassignedOnly: $assignee === 'none',
            productId: self::intOrNull($query['product_id'] ?? null),
            source: isset($query['source'])
                ? ShortageSource::tryFrom((string) $query['source'])
                : null,
            orderId: self::intOrNull($query['order_id'] ?? null),
            customerId: self::intOrNull($query['customer_id'] ?? null),
            search: self::search($query),
            includeArchivedOrders: $includeArchivedOrders,
        );
    }

    /**
     * The same filters with the status dropped — what the counts are built from.
     *
     * **Rebuilt field by field, and that is the trap.** `OrderFilters::withoutPaymentStatuses()`
     * is written the same way and ORDER-DELETE-AND-ARCHIVE §٦ names it as the silent failure of
     * that feature: a field added to the constructor and forgotten here makes the chip row count
     * a different set of rows from the list beside it. Every field below is deliberate; adding
     * one to this class means adding it here too.
     */
    public function withoutStatuses(): self
    {
        return new self(
            statuses: null,
            assignedToUserId: $this->assignedToUserId,
            unassignedOnly: $this->unassignedOnly,
            productId: $this->productId,
            source: $this->source,
            orderId: $this->orderId,
            customerId: $this->customerId,
            search: $this->search,
            includeArchivedOrders: $this->includeArchivedOrders,
        );
    }

    /**
     * Blank is not a filter — clearing the search box sends `search=`, and reading that as a term
     * would answer «show me everything» with an empty page.
     *
     * @param  array<string, mixed>  $query
     */
    private static function search(array $query): ?string
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';

        return $search !== '' ? $search : null;
    }

    /**
     * Accepts `status=new` and `status[]=new&status[]=searching` alike, and quietly drops a value
     * that names no status — the shape every other filter in this codebase settled on.
     *
     * @param  array<string, mixed>  $query
     * @return list<ShortageStatus>|null
     */
    private static function statuses(array $query): ?array
    {
        $raw = $query['status'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $statuses = array_filter(array_map(
            fn (mixed $value) => ShortageStatus::tryFrom((string) $value),
            is_array($raw) ? $raw : [$raw],
        ));

        return $statuses === [] ? null : array_values($statuses);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
