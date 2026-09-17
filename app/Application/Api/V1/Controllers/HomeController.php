<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Resources\HomeSummaryResource;
use App\Application\Controller;
use App\Domain\Customer\CustomerService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Investor\InvestorService;
use App\Domain\Order\OrderService;
use App\Domain\Order\Queries\OrderFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home
 *
 * The numbers the app opens on. One endpoint rather than four, because they are read together
 * and go stale together — a screen showing yesterday's order count beside today's customer
 * count is worse than one showing neither.
 *
 * **Signed in is the only requirement, and that is deliberate.** Every other endpoint declares a
 * `can:`, but this is the landing screen: guarding it on `orders.view` would hand a broken front
 * door to a designer whose whole job is `orders.status.designing`, on every launch. What it
 * exposes is aggregate counts of the shop's own work — not a single record, and nothing that is
 * not already on the screen the person is being shown.
 */
class HomeController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly OrderService $orders,
        private readonly CustomerService $customers,
        private readonly InvestorService $investors,
    ) {}

    /**
     * The home screen's summary
     *
     * Four counts, one card per order status, and one per payment state — each with the
     * Arabic to print on it.
     */
    public function summary(Request $request): JsonResponse
    {
        // **This is the one authenticated route in the file with no `can:` beside it**, so that
        // a designer holding a single status grant still has a working front door. That makes it
        // the one place an investor would otherwise read the whole shop's trading position on
        // his first launch — order counts, the customer count, every payment state.
        //
        // Keyed on the `investors.user_id` link rather than on a role, and narrowed further by
        // «cannot read orders», so an administrator who also happens to be an investor still
        // gets the board he came for.
        $investor = $this->investors->investorFor($request->user());

        if ($investor !== null && $request->user()?->can(PermissionName::ViewOrders->value) !== true) {
            return $this->successMessage('مرحباً بك — تفاصيل حسابك في شاشتك الخاصة');
        }

        $totals = $this->orders->totals();

        // Each context answers for its own numbers — this only puts them in one envelope. There
        // is no `home` table and there should not be one: a row that existed to be counted from
        // other tables is a number that can disagree with them.
        return $this->success(new HomeSummaryResource([
            'total_orders' => $totals['total'],
            'daily_orders' => $totals['daily'],
            'monthly_orders' => $totals['monthly'],
            'customers_count' => $this->customers->count(),
            // Unfiltered: the board is about the whole shop, not about a list somebody is
            // looking at. The same query the orders screen's own chips are counted from.
            'status_counts' => $this->orders->statusCounts(new OrderFilters),
            // The money axis, beside the workflow one. «كم طلبية لم تُدفع» is the question the
            // shop opens the app to ask, and it is not answerable from any of the four counts
            // above — being paid and being finished are different things.
            'payment_counts' => $this->orders->paymentStatusCounts(new OrderFilters),
            // **«بانتظار رسالة الجاهزية» — counted only for whoever the box belongs to.** It is a
            // fourth query on the landing screen, so it is not run for the shop's other twenty
            // people: the count is the grant's, exactly as the box is. Null means «لا تسأل»,
            // and the resource omits the key rather than sending a zero — a zero would draw an
            // empty box for somebody who is not doing this job. See ORDER-READY-MESSAGE.md §٦.
            'ready_message_count' => $request->user()?->can(PermissionName::ConfirmReadyMessage->value) === true
                ? $this->orders->count(new OrderFilters(readyMessageSent: false))
                : null,
        ]));
    }
}
