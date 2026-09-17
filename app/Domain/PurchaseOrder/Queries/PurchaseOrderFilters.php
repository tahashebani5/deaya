<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Queries;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;

final readonly class PurchaseOrderFilters
{
    /**
     * @param  list<PurchaseOrderStatus>|null  $statuses  more than one, because the queues people
     *                                                    actually ask for are groups: «كل ما لم
     *                                                    يصل بعد» is `new` and `arrived`
     *                                                    together, and a screen that had to call
     *                                                    twice to draw one number would either
     *                                                    call twice or show the wrong number.
     */
    public function __construct(
        public ?int $vendorId = null,
        public ?int $warehouseId = null,
        public ?array $statuses = null,
        /** Matches the vendor's name, the warehouse's name, or — for a number — the order's id. */
        public ?string $search = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query  Validated query-string values.
     */
    public static function fromArray(array $query): self
    {
        return new self(
            vendorId: self::intOrNull($query['vendor_id'] ?? null),
            warehouseId: self::intOrNull($query['warehouse_id'] ?? null),
            statuses: self::statuses($query),
            search: self::search($query),
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
     * Accepts `status=new` and `status[]=new&status[]=arrived` alike, and quietly drops a value
     * that names no status — the same shape {@see OrderFilters::statuses()} settled on. A filter
     * nobody can satisfy would answer with an empty page and read as «no purchase orders»
     * rather than «you asked for something that does not exist».
     *
     * @param  array<string, mixed>  $query
     * @return list<PurchaseOrderStatus>|null
     */
    private static function statuses(array $query): ?array
    {
        $raw = $query['status'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $statuses = array_filter(array_map(
            fn (mixed $value) => PurchaseOrderStatus::tryFrom((string) $value),
            is_array($raw) ? $raw : [$raw],
        ));

        return $statuses === [] ? null : array_values($statuses);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
