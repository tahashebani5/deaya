<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Billboard\StoreBillboardRequest;
use App\Application\Api\V1\Requests\Billboard\UpdateBillboardRequest;
use App\Application\Api\V1\Resources\BillboardResource;
use App\Application\Controller;
use App\Domain\Marketing\DTOs\BillboardData;
use App\Domain\Marketing\MarketingService;
use App\Domain\Marketing\Models\Billboard;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Billboards
 *
 * The banners on the customer app's home screen — the first thing this shop says to a customer
 * rather than about its work.
 *
 * **Every route here is guarded by `billboards.manage`, and there is no view/manage pair.**
 * Nobody reads this list except to change it: the customer app has its own endpoint carrying no
 * permission at all, and staff have no screen that merely displays posters.
 *
 * **Unlike customers and products, a banner is genuinely deletable.** Those two are deactivated
 * because orders point at them forever; nothing points at a poster, so one put up by mistake
 * comes down.
 */
class BillboardController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly MarketingService $marketing) {}

    /**
     * List the billboards
     *
     * **Every banner, including the ones nobody can see** — switched off, not yet started, long
     * finished. A banner you cannot see is one you cannot fix. Each row carries
     * `is_showing_now`, which is the three scheduling fields resolved into the one answer the
     * screen draws.
     */
    public function index(): JsonResponse
    {
        return $this->success(BillboardResource::collection($this->marketing->all()->load('product')));
    }

    /**
     * Put a banner up
     *
     * Send as `multipart/form-data`. A tap may open a product **or** an external link, never
     * both — two destinations would mean a precedence rule nobody wrote down.
     */
    public function store(StoreBillboardRequest $request): JsonResponse
    {
        $billboard = $this->marketing->create(
            BillboardData::fromArray($request->validated()),
            $request->file('image'),
            $request->user()?->getAuthIdentifier(),
        );

        return $this->created(new BillboardResource($billboard), 'تم إضافة اللوحة بنجاح');
    }

    /**
     * Edit a banner
     *
     * **A field left out is a field left alone.** Sending `{"is_active": false}` switches a
     * banner off without clearing the schedule set for it last week — the edit staff make most,
     * and the one a blanket update would get wrong. Send a new `image` to replace the picture.
     */
    public function update(UpdateBillboardRequest $request, Billboard $billboard): JsonResponse
    {
        $updated = $this->marketing->update(
            $billboard,
            BillboardData::fromArray($request->validated()),
            $request->file('image'),
        );

        return $this->success(new BillboardResource($updated), 'تم تحديث اللوحة');
    }

    /**
     * Take a banner down
     */
    public function destroy(Billboard $billboard): JsonResponse
    {
        $this->marketing->delete($billboard);

        return $this->successMessage('تم حذف اللوحة');
    }
}
