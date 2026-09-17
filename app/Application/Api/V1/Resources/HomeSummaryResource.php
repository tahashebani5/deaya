<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The home screen's opening numbers.
 *
 * Not a model resource: there is no `HomeSummary` row anywhere, and inventing one to satisfy the
 * pattern would be a table that exists to be counted from other tables. What it wraps is an
 * array the controller assembled from two contexts.
 *
 * @property array{
 *     total_orders: int,
 *     customers_count: int,
 *     daily_orders: int,
 *     monthly_orders: int,
 *     status_counts: array<string, int>,
 *     payment_counts: array<string, int>,
 *     ready_message_count: int|null,
 * } $resource
 */
class HomeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $summary */
        $summary = $this->resource;

        /** @var array<string, int> $counts */
        $counts = $summary['status_counts'];

        /** @var array<string, int> $paymentCounts */
        $paymentCounts = $summary['payment_counts'];

        return [
            'total_orders' => $summary['total_orders'],
            'customers_count' => $summary['customers_count'],
            'daily_orders' => $summary['daily_orders'],
            'monthly_orders' => $summary['monthly_orders'],

            // **A list, in the machine's own order, with the zeros left in.** The app draws a
            // card per row and holds no list of its own, so a status added to the business
            // appears on the home screen without an app release — and a status with nothing in
            // it still has a card, because zero is an answer and a missing card is not.
            'statuses' => array_map(
                fn (OrderStatus $status) => [
                    'status' => $status->value,
                    // The Arabic travels with the number: one status, one word, wherever it is
                    // drawn — the same rule the queue chips follow.
                    'label' => $status->label(),
                    'count' => $counts[$status->value] ?? 0,
                ],
                OrderStatus::cases(),
            ),

            // **The money axis, beside the workflow one and never folded into it.** «كم طلبية لم
            // تُدفع» cannot be answered from any of the four counts above: an order can be
            // finished and unpaid, or paid and not yet printed. Same list-with-its-Arabic shape
            // as `statuses`, for the same reason — a state added to the business appears on the
            // home screen without an app release.
            'payments' => array_map(
                fn (PaymentStatus $status) => [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'count' => $paymentCounts[$status->value] ?? 0,
                ],
                PaymentStatus::cases(),
            ),

            // **«بانتظار رسالة الجاهزية» — a box, not a board.** One number, because it is one
            // queue belonging to one person: the employee who tells customers their bags are
            // ready, and nobody else. It is absent — not zero — for a reader without
            // `orders.ready_message`, so the screen has nothing to draw rather than an empty
            // card in a job that is not theirs. See Docs/orders/ORDER-READY-MESSAGE.md §٦.
            //
            // The Arabic travels with the number, like every other card on this screen: the
            // screen the box opens is titled with the box's own word, and this app holds no
            // table of them.
            'ready_message' => $this->when(
                $summary['ready_message_count'] !== null,
                fn (): array => [
                    'count' => (int) $summary['ready_message_count'],
                    'label' => 'بانتظار رسالة الجاهزية',
                ],
            ),
        ];
    }
}
