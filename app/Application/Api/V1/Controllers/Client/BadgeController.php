<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Controller;
use App\Domain\Customer\CustomerService;
use App\Domain\Customer\Enums\CustomerBadge;
use App\Domain\Customer\Models\Customer;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Badges
 *
 * What is waiting for the customer, as a number per tile.
 *
 * **One endpoint for every badge rather than one each.** The app draws several tiles and would
 * otherwise ask several times on every launch, on a connection where each round trip is the
 * expensive part. A badge added to {@see CustomerBadge} appears in this answer without a new
 * route, a new call site, or a new thing for the app to forget to ask for.
 *
 * **It answers with zeros as well.** The app clears a badge from this map, so a count that
 * dropped to nothing has to arrive saying so — omitting the empty ones would leave the last
 * number on a tile until the app was reinstalled.
 *
 * **Cheap on purpose.** This is called on every launch and every resume, so each badge is one
 * aggregate query and nothing here loads a row the customer is not about to see.
 */
class BadgeController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly CustomerService $customers) {}

    /**
     * What is waiting
     *
     * Every badge this app knows about, counted for the signed-in customer. Keys are stable
     * strings — `support` today — and a key the app has never heard of is one it ignores, so an
     * older build cannot be broken by a badge added to the business.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $this->success($this->customers->badgesFor((int) $customer->getKey()));
    }
}
