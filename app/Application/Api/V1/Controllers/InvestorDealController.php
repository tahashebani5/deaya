<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Investor\FundPurchaseOrderRequest;
use App\Application\Api\V1\Requests\Investor\StoreDealExpenseRequest;
use App\Application\Api\V1\Resources\DealOrderResource;
use App\Application\Api\V1\Resources\InvestorDealResource;
use App\Application\Api\V1\Resources\OrderInvestorShareResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Investor\DTOs\DealExpenseData;
use App\Domain\Investor\DTOs\FundPurchaseOrderData;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Order\Models\Order;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Investor deals
 *
 * صفقات المستثمرين — one financed purchase of stock. A deal names the shelves it funds and the
 * people who are in it; from there the system does the rest.
 *
 * **A deal is born on its purchase order and nowhere else.** There is no endpoint that creates,
 * opens, cancels or claims for a deal by hand: the fraction of the goods its partners own is
 * derived from the order's cost, and a deal without an order had nothing to derive it from and
 * paid its partners for goods they had not bought. The owner closed that door on 2026-09-05.
 *
 * **Nobody chooses a deal when goods arrive or when they are sold.** The funding is declared
 * against a purchase order before the lorry gets here, and the sale draws from the oldest cost
 * layer first exactly as it always has. The deal's numbers are read out of that afterwards.
 *
 * A deal carries no stored money: capital, profit and quantities are all walked from the ledgers
 * when a screen asks.
 */
class InvestorDealController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly InvestorService $investors) {}

    /**
     * List deals
     *
     * Newest first. Filter by `status`, by `investor_id`, or search the name and code.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            InvestorDealResource::collection(
                $this->investors->paginateDeals($request->only(['search', 'status', 'investor_id']), $perPage),
            ),
        );
    }

    /**
     * Fund a purchase order
     *
     * The ordinary way a deal is born: on the order itself, name the partners and what each one
     * put in. The order's lines become the deal's shelves, every one of them is claimed for it,
     * and the money moves from each wallet into the deal — all in one transaction, so a man who
     * has not got what he promised leaves nothing half-made behind.
     *
     * The percentages are not asked for. They are the amounts, split so they sum to exactly 100.
     *
     * Refused once a line has been received: who paid for goods is declared before they arrive,
     * because the cost layer is stamped at the gate and can never be stamped afterwards.
     */
    public function fundPurchaseOrder(FundPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $deal = $this->investors->fundPurchaseOrder(
            (int) $purchaseOrder->getKey(),
            FundPurchaseOrderData::fromArray($request->validated()),
            $request->user()?->id,
        );

        return $this->created(
            new InvestorDealResource($deal),
            'تم إنشاء الصفقة وتمويل أمر الشراء',
        );
    }

    /**
     * Show a deal
     *
     * The whole screen in one payload: the deal, its shelves, its investors, what its goods are
     * doing, and what each investor is holding and has earned.
     *
     * **`orders_profit` is the deal's own money, and it is not `balances`.** `balances` is the
     * ledger — what was actually paid, which happens at «تم الاستلام» and not a day sooner.
     * `orders_profit` adds up what the deal made on every order that sold its goods, keeping the
     * delivered ones apart from the ones still on the road, because the second figure is a
     * forecast: a parcel that comes home cancelled hands those goods back to this deal.
     */
    public function show(InvestorDeal $deal): JsonResponse
    {
        $deal->load(['product', 'items.stockItem', 'shares.investor']);

        $deal->setAttribute('balances', $this->investors->dealBalances((int) $deal->getKey()));
        $deal->setAttribute('stock', $this->investors->dealStock((int) $deal->getKey()));
        $deal->setAttribute('orders_profit', $this->investors->dealOrdersProfit((int) $deal->getKey()));

        return $this->success(new InvestorDealResource($deal));
    }

    /**
     * The orders that sold this deal's goods
     *
     * «أي طلبيات كانت مرتبطة بالصفقة وكم أدخلت» — one row per order, newest first, each carrying
     * what it drew off the shelf and what that earned.
     *
     * **Two figures, and they are not the same one.** `profit` is what the deal made on the
     * order; `investors_share` is what was written into the ledger for it, which is that profit
     * times the deal's percentage. `investors_share` is null until the order reaches
     * «تم الاستلام», because nothing is paid before then — and an order still on the road appears
     * here anyway, since it is holding this deal's stock and is what refuses to let it close.
     *
     * A cancelled order is absent: its goods went back into this deal's own layers, so it took
     * nothing and earned nothing.
     */
    public function orders(Request $request, InvestorDeal $deal): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            DealOrderResource::collection($this->investors->dealOrders((int) $deal->getKey(), $perPage)),
        );
    }

    /**
     * Who took money out of one order
     *
     * The mirror of the deal's own order list, asked from the order's end: which deals financed
     * the stock this order used, what each of them made on it, what the investors' share of that
     * is, and whether it has actually reached their ledgers yet.
     *
     * **Two roads, never added together.** A `plain_sale` row is what the press *paid* for plain
     * bags when they left the shelf — money inside this order's material cost, settled before the
     * parcel moved. An `order_profit` row is a share carved out of the order's own profit at
     * delivery. One subtotal over both would be wrong whichever way it was read.
     */
    public function investorShares(Order $order): JsonResponse
    {
        return $this->success(
            OrderInvestorShareResource::collection(
                $this->investors->investorSharesOfOrder((int) $order->getKey()),
            ),
        );
    }

    /**
     * Close a deal
     *
     * Settles each investor's result and returns his money to his wallet — and this is the only
     * moment his profit becomes withdrawable.
     *
     * Refused while any stock is left, and refused while any order that took this deal's goods
     * has not reached the customer: stock leaves the shelf days before a parcel is delivered,
     * and a parcel that comes home and is cancelled puts the goods back into this deal's layers.
     */
    public function close(InvestorDeal $deal): JsonResponse
    {
        return $this->success(
            new InvestorDealResource($this->investors->closeDeal($deal)),
            'تم إغلاق الصفقة وتسوية حسابات المستثمرين',
        );
    }

    /**
     * Record a deal expense
     *
     * Shipping, customs, transport, storage. The investors are charged their share of it at
     * once, by the same percentages a profit is split by.
     *
     * Costs typed on a purchase order are **not** entered here: they are already inside what the
     * goods cost, and charging them again pays for one invoice twice.
     */
    public function storeExpense(StoreDealExpenseRequest $request, InvestorDeal $deal): JsonResponse
    {
        $this->investors->recordDealExpense(
            $deal,
            DealExpenseData::fromArray($request->validated()),
            $request->user()?->id,
        );

        return $this->successMessage('تم تسجيل المصروف وخصم حصة المستثمرين منه');
    }

    /**
     * A deal's history
     *
     * Every change to the deal itself, newest first — who made it and what it was before.
     *
     * **Its money is not here, deliberately.** Capital, profit and quantities are walked from
     * the ledgers rather than stored, so `GET /investor-deals/{deal}` is what reports them.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, InvestorDeal $deal, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $deal, $audit);
    }
}
