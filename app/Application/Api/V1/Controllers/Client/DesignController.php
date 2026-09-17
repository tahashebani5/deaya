<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Requests\Client\Design\StoreClientDesignRequest;
use App\Application\Api\V1\Requests\Client\Design\UpdateClientDesignRequest;
use App\Application\Api\V1\Resources\Client\CustomerDesignSummaryResource;
use App\Application\Controller;
use App\Domain\Customer\Actions\DeleteCustomerDesign;
use App\Domain\Customer\Actions\UploadCustomerDesign;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My designs
 *
 * The customer's own artwork library — upload the logo once, and every order points at it
 * instead of the file being sent again.
 *
 * **No customer id appears in any path here, and no design is ever looked up by id alone.**
 * The staff routes next door are `customers/{customer}/designs/{design}` and lean on Laravel's
 * `scoped()` to make another customer's design a 404 by construction. There is no parent segment
 * to scope against here, so {@see self::ownedDesign()} does that job explicitly: every write
 * resolves through the signed-in customer's own relation, and a foreign id is a 404 rather than
 * a row somebody else owns. `CustomerDesignLibraryTest` watches both directions.
 *
 * The actions are the same ones the staff endpoints call, so the rules that matter are stated
 * once: an upload of a file already held is answered with the design that exists rather than a
 * second copy, and removing a design hides the row while the file stays exactly where it is —
 * an order placed last year must still be able to show what was printed.
 */
class DesignController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly UploadCustomerDesign $uploadDesign,
        private readonly DeleteCustomerDesign $deleteDesign,
    ) {}

    /**
     * My designs
     *
     * Newest first, and uncapped by design — a customer holds at most fifty, so there is nothing
     * to page.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success(
            CustomerDesignSummaryResource::collection($this->customer($request)->designs()->get()),
        );
    }

    /**
     * Upload a design
     *
     * Send as `multipart/form-data`. Accepts pdf, jpeg, png or webp — never svg.
     *
     * **Idempotent.** Sending a file already in the library answers `200` with the design that
     * already exists rather than storing a second copy: a dropped connection on a phone leaves
     * the app unable to know whether the upload landed, so the retry has to be free.
     */
    public function store(StoreClientDesignRequest $request): JsonResponse
    {
        [$design, $wasCreated] = ($this->uploadDesign)(
            customer: $this->customer($request),
            file: $request->file('file'),
            label: $request->string('label')->toString() ?: null,
            // `notes` is deliberately not forwarded: it is what staff write to each other about
            // a design, and nothing a customer sends may land in it.
            notes: null,
        );

        $resource = new CustomerDesignSummaryResource($design);

        return $wasCreated
            ? $this->created($resource, 'تم رفع التصميم بنجاح')
            : $this->success($resource, 'هذا التصميم محفوظ مسبقاً');
    }

    /**
     * Rename a design
     *
     * The name only. The file itself is never swapped — a new version is a new upload.
     */
    public function update(UpdateClientDesignRequest $request, int $design): JsonResponse
    {
        $owned = $this->ownedDesign($request, $design);

        $owned->update(['label' => $request->string('label')->toString()]);

        return $this->success(new CustomerDesignSummaryResource($owned->refresh()), 'تم تحديث التصميم');
    }

    /**
     * Remove a design
     *
     * Hides it from the library. The file is kept, so an order that used it still resolves.
     */
    public function destroy(Request $request, int $design): JsonResponse
    {
        ($this->deleteDesign)($this->ownedDesign($request, $design));

        return $this->successMessage('تم حذف التصميم');
    }

    /**
     * One of *this* customer's designs, or a 404.
     *
     * **Route-model binding is deliberately not used for `{design}`.** Binding would resolve the
     * row by id alone and hand the controller somebody else's design to check afterwards — and a
     * check somebody has to remember is the thing `scoped()` exists to replace on the staff
     * routes. Resolving through the relation means a foreign id never becomes an object at all.
     */
    private function ownedDesign(Request $request, int $designId): CustomerDesign
    {
        /** @var CustomerDesign */
        return $this->customer($request)->designs()->findOrFail($designId);
    }

    /**
     * The signed-in customer, narrowed from the guard's contract to the model. The route group's
     * `auth:customer` middleware is what makes the assertion true.
     */
    private function customer(Request $request): Customer
    {
        $customer = $request->user();

        assert($customer instanceof Customer);

        return $customer;
    }
}
