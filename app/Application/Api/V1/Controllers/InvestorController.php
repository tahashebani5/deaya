<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Investor\StoreInvestorRequest;
use App\Application\Api\V1\Requests\Investor\StoreWalletEntryRequest;
use App\Application\Api\V1\Requests\Investor\UpdateInvestorRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Resources\InvestorResource;
use App\Application\Api\V1\Resources\InvestorWalletEntryResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Investor\DTOs\InvestorData;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Investors
 *
 * المستثمرون — the people whose money finances the stock, and the wallet each one's money sits
 * in. A wallet has two pots kept deliberately apart: **capital**, which he put in and can take
 * back out, and **profit**, which his deals earned him.
 *
 * Nothing here stores a balance. Every figure is a walk of `investor_wallet_entries`, which is
 * the same rule the order ledger follows and the reason the numbers can never drift from the
 * rows they summarise.
 */
class InvestorController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly InvestorService $investors) {}

    /**
     * List investors
     *
     * Active first, then by name. `search` matches the name, the code and the phone.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $page = $this->investors->paginateInvestors($request->only(['search', 'is_active']), $perPage);

        // What each man has with us and what he has earned, drawn on the row itself. **One query
        // for the whole page**, not one ledger walk per card — the register is a list, and a
        // list that costs fifty queries to draw two numbers is a list that gets slower every
        // time somebody is added.
        $totals = $this->investors->balancesForMany(
            $page->getCollection()->map(fn ($investor) => (int) $investor->getKey())->all(),
        );

        $page->getCollection()->each(
            fn ($investor) => $investor->setAttribute('totals', $totals[(int) $investor->getKey()] ?? null),
        );

        return $this->successWithPagination(InvestorResource::collection($page));
    }

    /**
     * Create an investor
     */
    public function store(StoreInvestorRequest $request): JsonResponse
    {
        $investor = $this->investors->createInvestor(
            InvestorData::fromArray($request->validated()),
            $request->user()?->id,
        );

        return $this->created(new InvestorResource($investor), 'تم إضافة المستثمر');
    }

    /**
     * Show an investor
     *
     * With his balances: what is in his wallet, and what each of his deals is holding and has
     * earned him.
     */
    public function show(Investor $investor): JsonResponse
    {
        $investor->setAttribute('balances', $this->investors->balancesFor((int) $investor->getKey()));

        return $this->success(new InvestorResource($investor));
    }

    /**
     * Update an investor
     */
    public function update(UpdateInvestorRequest $request, Investor $investor): JsonResponse
    {
        return $this->success(
            new InvestorResource($this->investors->updateInvestor($investor, InvestorData::fromArray($request->validated()))),
            'تم تحديث بيانات المستثمر',
        );
    }

    /**
     * Activate or retire an investor
     *
     * There is no delete: a man with money in the ledger cannot be removed without the ledger
     * losing its subject.
     */
    public function activation(SetActivationRequest $request, Investor $investor): JsonResponse
    {
        return $this->success(
            new InvestorResource($this->investors->setInvestorActivation($investor, (bool) $request->validated('is_active'))),
            'تم تحديث حالة المستثمر',
        );
    }

    /**
     * An investor's statement
     *
     * Every movement of his money, newest first, each row carrying what it did to each of the
     * four balances. `deposit`, `withdrawal`, `allocation` and `profit_withdrawal` were recorded
     * by a person; `profit`, `loss`, `release` and `profit_release` were written by an order or
     * by a deal closing, and each names the source it came from.
     */
    public function statement(Request $request, Investor $investor): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $entries = InvestorWalletEntry::query()
            ->with(['deal', 'recordedBy', 'reversedEntry'])
            ->where('investor_id', $investor->getKey())
            ->when(
                $request->filled('investor_deal_id'),
                fn ($q) => $q->where('investor_deal_id', $request->integer('investor_deal_id')),
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->successWithPagination(InvestorWalletEntryResource::collection($entries));
    }

    /**
     * Record a movement of an investor's money
     *
     * Four types only: `deposit` and `withdrawal` move capital between him and the company,
     * `allocation` commits wallet capital to a deal, and `profit_withdrawal` pays out profit
     * that a closed deal has released.
     *
     * **Profit cannot be withdrawn from a running deal**, and that is not a rule checked here:
     * profit only reaches the wallet when a deal closes, so there is simply no path to it.
     */
    public function storeWalletEntry(StoreWalletEntryRequest $request, Investor $investor): JsonResponse
    {
        $entry = $this->investors->recordWalletEntry(
            WalletEntryData::fromArray($request->validated(), (int) $investor->getKey()),
            $request->user()?->id,
        );

        return $this->created(new InvestorWalletEntryResource($entry), 'تم تسجيل الحركة');
    }

    /**
     * An investor's history
     *
     * Every change to the investor himself, newest first — who made it and what it was before.
     *
     * **His money is not here, deliberately.** The wallet is an append-only ledger rather than a
     * change log, and `GET /investors/{investor}/statement` is the reader built for it.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, Investor $investor, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $investor, $audit);
    }
}
