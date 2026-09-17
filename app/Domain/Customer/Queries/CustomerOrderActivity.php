<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * What the customer list needs to know about orders, stated by the side that needs it.
 *
 * **A port, and the direction is the whole point.** `Order` already knows `Customer` — that is
 * the way the foreign key runs — so `Customer::orders()` would close the loop and make the two
 * domains a cycle. The same reasoning that kept the count out of the model (see
 * `OrderCountsByCustomerQuery`) applies here, but a count could be stamped on afterwards by the
 * controller and *this* cannot: filtering and ordering happen inside the query that pages, so
 * the list has to be able to say it in SQL.
 *
 * So the Customer module declares the two questions it has, and the Order module answers them
 * in `App\Domain\Order\Queries\OrderCustomerActivity` — named here in prose rather than in a
 * `{@see}`, because Pint turns one of those into a real `use` statement and this file having
 * no line pointing at the Order module is the property it exists to keep. Customer holds an
 * interface it wrote itself; the two are joined in `AppServiceProvider` and nowhere else.
 *
 * Both methods return **correlated** subqueries: they refer to `customers.id` and are only
 * meaningful inside a query over `customers`.
 */
interface CustomerOrderActivity
{
    /**
     * When this customer last placed an order — null for one who never has.
     *
     * For `select` and `orderBy`; it yields a single value per customer row.
     */
    public function lastOrderedAt(): Builder;

    /**
     * Has a row when this customer has ever ordered, and none when they have not.
     *
     * For `whereExists` / `whereNotExists`. Separate from {@see self::lastOrderedAt()} rather
     * than a null check on it, because a null in the select list cannot be filtered on by its
     * alias — and because "have they ever" is a cheaper question than "when was the last".
     */
    public function everOrdered(): Builder;
}
