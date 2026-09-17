<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

/**
 * Rounding money, once.
 *
 * A same-domain copy of {@see \App\Domain\Order\Support\Money} rather than a shared import — the
 * same reasoning {@see \App\Domain\PurchaseOrder\Support\Money} already gives for its own copy:
 * reaching into another context for a two-method helper opens a dependency RULES.md §3 does not
 * otherwise ask for.
 *
 * bcmath **truncates** rather than rounds, so `bcadd('1.005', '0', 2)` is `1.00` — a batch
 * consumption's cost quietly short by a fraction every time it is drawn from, which becomes a
 * real discrepancy over a year of fulfillments. Adding half a minor unit before cutting gives
 * round-half-away-from-zero.
 */
final class Money
{
    public const SCALE = 2;

    /** Rounds a decimal string to two places, half away from zero. */
    public static function round(string $value): string
    {
        $half = bccomp($value, '0', 8) < 0 ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 8), '0', self::SCALE);
    }

    /** Adds a list of decimal strings at full precision, then rounds once at the end. */
    public static function sum(string ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, $value, 8);
        }

        return self::round($total);
    }
}
