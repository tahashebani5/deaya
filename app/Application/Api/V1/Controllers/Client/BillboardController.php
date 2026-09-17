<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Resources\Client\ClientBillboardResource;
use App\Application\Controller;
use App\Domain\Marketing\MarketingService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * The billboard
 *
 * What the shop is showing on the home screen right now.
 *
 * **The schedule is applied here, not in the app.** A banner whose window has closed is not
 * hidden by the client — it never leaves the server. That is the difference between this
 * endpoint and the management one, and it is why `MarketingService` has two read methods rather
 * than one with a flag.
 */
class BillboardController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly MarketingService $marketing) {}

    /**
     * What is showing
     *
     * In the order staff arranged it. Never paged — a carousel is a handful of pictures.
     */
    public function index(): JsonResponse
    {
        return $this->success(ClientBillboardResource::collection($this->marketing->showingNow()));
    }
}
