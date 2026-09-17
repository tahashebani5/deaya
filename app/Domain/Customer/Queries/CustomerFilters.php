<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

final readonly class CustomerFilters
{
    public function __construct(
        /** Matches against name, code, or phone. */
        public ?string $search = null,
        /** null = both active and inactive. */
        public ?bool $isActive = null,
        /**
         * null = everyone. `false` is the one that is asked for — «زبائن بدون طلب» — and
         * `true` is its complement, kept because a filter that only narrows one way is a
         * filter somebody has to invert by hand later.
         */
        public ?bool $hasOrders = null,
        /** How the page is ordered. */
        public CustomerSort $sort = CustomerSort::Newest,
    ) {}

    /**
     * @param  array<string, mixed>  $query  Validated query-string values.
     */
    public static function fromArray(array $query): self
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';

        return new self(
            search: $search !== '' ? $search : null,
            isActive: self::boolean($query, 'is_active'),
            hasOrders: self::boolean($query, 'has_orders'),
            sort: CustomerSort::fromWire(isset($query['sort']) ? (string) $query['sort'] : null),
        );
    }

    /**
     * Whether this reading of the list needs the orders — and so whether it is a question only
     * a holder of `orders.view` may ask.
     *
     * On the filters rather than in the controller because it is one fact about them: adding a
     * third order-derived option must not need somebody to remember a second place.
     */
    public function readsOrders(): bool
    {
        return $this->hasOrders !== null || $this->sort->readsOrders();
    }

    /**
     * A tri-state flag: absent or null means «no opinion», not «false».
     *
     * @param  array<string, mixed>  $query
     */
    private static function boolean(array $query, string $key): ?bool
    {
        if (! array_key_exists($key, $query) || $query[$key] === null) {
            return null;
        }

        return filter_var($query[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
