<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\DTOs\OrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;

/**
 * A customer orders from the app.
 *
 * **Built on {@see CreateOrder} rather than beside it**, so an order a customer asked for is the
 * same kind of row as one a clerk typed in: the same code, the same destination resolution, the
 * same per-line pricing through `CatalogService::quote()`, the same design attachment with the
 * same ownership check, the same totals. A second way of making an order would be a second
 * definition of what an order is, and the two would disagree the first time either changed.
 *
 * What it changes is one thing: the status the order is born in.
 *
 * **«بانتظار المراجعة», never «جديدة».** «جديدة» means *verified* — an order a clerk writes down
 * carries a check nobody can see, because a person spoke to the customer before typing it, and
 * from «جديدة» the next move takes goods off the shelf. An order that arrived at 2am from a
 * phone carries no such check. Landing it in «جديدة» would put it in the same column of the same
 * board as one that does, and the first person to read the board would have no way to tell them
 * apart.
 *
 * **`actor` is deliberately null.** Nobody on the staff made this order, and saying so is honest
 * rather than lossy: `created_by` stays empty and the opening timeline row names no one, which
 * is exactly the fact «this came from the app» is. The person who accepts it is recorded on the
 * transition that does the accepting.
 *
 * The outsourcing rule does not bind here — see the guard in {@see ChangeOrderStatus}. A request
 * may be incomplete in that one way; an order may not.
 */
final class RequestOrder
{
    public function __construct(private readonly CreateOrder $createOrder) {}

    public function __invoke(OrderData $data): Order
    {
        return ($this->createOrder)($data, null, OrderStatus::Requested);
    }
}
