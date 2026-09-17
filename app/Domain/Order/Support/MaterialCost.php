<?php

declare(strict_types=1);

namespace App\Domain\Order\Support;

use App\Domain\Inventory\InventoryService;
use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Actions\RestateOrderStockDeduction;
use App\Domain\Order\Models\OrderItem;

/**
 * What one line's material cost, and what it *paid* — which since سعر السادة are two numbers.
 *
 * **The one place the two are ever derived**, shared by {@see DeductOrderStock} and
 * {@see RestateOrderStockDeduction} because a restatement recomputes exactly what the deduction
 * computed, off a fresh draw. Restating it in the second class is how a corrected line comes to
 * carry a cost the original never would have.
 *
 * ```
 * charged  = Σ per draw:  priced ? price × quantity : total_cost
 * actual   = Σ per draw:  total_cost                              ← always, exactly as before
 * purchased = any draw was priced
 * ```
 *
 * **A draw is priced when two things are true at once**: the cost layer carries a
 * `printing_sale_price` — the deal that financed it sells to the press at an agreed rate — *and*
 * the line is one the press actually runs ({@see OrderItem::isPrinted()}). Either alone changes
 * nothing: the company's own stock has no price to charge, and a سادة line sold off the shelf as
 * it stands is not a printing job buying material, so its investor keeps riding the sale itself.
 *
 * That is the whole of «الساده تقعد زي ماهي زي كل شيء حالياً، اللي بيختلف فقط لما تكون طلبية
 * طباعة» — one condition, in one place, rather than the same question asked by four callers.
 *
 * Nothing here reads the database. It is handed the draws
 * {@see InventoryService::consumptionBreakdownFor()} returns, which is what makes the figure
 * reproducible a year later: every input is written once and never moves again.
 */
final readonly class MaterialCost
{
    private function __construct(
        /** What the line pays for its material — the press's price where one applies. */
        public string $charged,
        /** What those goods actually cost the business, whoever paid. */
        public string $actual,
        /** Whether any of it was bought off a deal at an agreed price. */
        public bool $purchased,
    ) {}

    /**
     * @param  list<array{printing_sale_price: ?string, quantity: string, total_cost: string}>  $draws
     *                                                                                                  every draw of this line's fulfilment movement
     */
    public static function forDraws(array $draws, bool $lineIsPrinted): self
    {
        $charged = '0.00';
        $actual = '0.00';
        $purchased = false;

        foreach ($draws as $draw) {
            $actual = bcadd($actual, $draw['total_cost'], 2);

            $price = $lineIsPrinted ? $draw['printing_sale_price'] : null;

            if ($price === null) {
                $charged = bcadd($charged, $draw['total_cost'], 2);

                continue;
            }

            $purchased = true;
            // Rounded per draw rather than once at the end: this is the figure the investor is
            // paid against on this very draw, and a total rounded later would not add up to the
            // rows underneath it.
            $charged = bcadd($charged, Money::round(bcmul($price, $draw['quantity'], 8)), 2);
        }

        return new self(Money::round($charged), Money::round($actual), $purchased);
    }
}
