<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;
use Illuminate\Support\Carbon;

/**
 * How much work has come in: ever, today, and this month.
 *
 * **Counted from `placed_at`, not `created_at`.** They are the same instant for every order the
 * API takes, and they stop being the same the day an old order is imported — at which point
 * «طلبات اليوم» should say when the customer placed it, not when we typed it in.
 *
 * **The day is the business's day, not the server's.** `app.timezone` is UTC, and Libya is two
 * hours ahead of it: an order taken at one in the morning is 23:00 the previous day in UTC, so a
 * naive `whereDate` would drop it out of today's count and into yesterday's — a number that is
 * quietly wrong for the first two hours of every day, which is exactly the kind of wrong nobody
 * reports.
 */
final class OrderTotalsQuery
{
    /**
     * @return array{total: int, daily: int, monthly: int}
     */
    public function __invoke(): array
    {
        $now = Carbon::now(self::timezone());

        return [
            'total' => Order::query()->count(),
            'daily' => self::placedBetween($now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'monthly' => self::placedBetween($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
        ];
    }

    /**
     * Both ends converted back to UTC, because that is what the column holds — comparing a
     * local-time boundary against a UTC column is the same off-by-two-hours in a subtler place.
     */
    private static function placedBetween(Carbon $from, Carbon $to): int
    {
        return Order::query()
            ->whereBetween('placed_at', [$from->utc(), $to->utc()])
            ->count();
    }

    /**
     * Where the business is, which is not necessarily where the server is.
     *
     * Configurable rather than hard-coded so a second branch in another zone is a `.env` line,
     * and defaulted so nobody has to set it for the shop that exists today.
     */
    private static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Africa/Tripoli');
    }
}
