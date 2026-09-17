<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What one deal took out of one order, and by which road.
 *
 * **`kind` is the field everything else on the card hangs off**, because the two roads are two
 * different statements about the same order:
 *
 *   * `plain_sale` — the press bought this deal's plain bags at سعر السادة when they left the
 *     shelf. `goods_amount` is what it paid for them, `profit` the margin in that, and the money
 *     was in the investor's ledger before the parcel moved. This is **not** a share of the
 *     order's profit: it sits inside the order's material cost.
 *   * `order_profit` — no price was agreed for this deal, so its partners ride the sale and take
 *     their share **out of** the order's own profit, at «تم الاستلام». `goods_amount` is null:
 *     nothing was bought.
 *
 * `investors_share` is what this deal's terms give the partners of `profit`; `company_share` is
 * the rest, which is the company's whether it is the second partner in the goods or the shop
 * that did the work. `is_paid` reads the **ledger**, never the order's status: an order can be
 * delivered with a share that rounded to nothing, and no row is written for a zero.
 *
 * @mixin array<string, mixed>
 */
class OrderInvestorShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'deal_id' => $this['deal_id'],
            'deal_code' => $this['deal_code'],
            'kind' => $this['kind'],
            'kind_label' => $this['kind'] === 'plain_sale'
                ? 'بيع السادة للمطبعة'
                : 'حصة من ربح الطلبية',
            'goods_amount' => $this['goods_amount'],
            'profit' => $this['profit'],
            'investors_share' => $this['investors_share'],
            'company_share' => $this['company_share'],
            'is_paid' => $this['is_paid'],
            'paid_amount' => $this['paid_amount'],
            'paid_at' => $this['paid_at'],
        ];
    }
}
