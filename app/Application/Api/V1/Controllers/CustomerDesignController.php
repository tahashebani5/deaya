<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Customer\StoreCustomerDesignRequest;
use App\Application\Api\V1\Requests\Customer\UpdateCustomerDesignRequest;
use App\Application\Api\V1\Resources\CustomerDesignResource;
use App\Application\Controller;
use App\Domain\Customer\Actions\DeleteCustomerDesign;
use App\Domain\Customer\Actions\UploadCustomerDesign;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Customer designs
 *
 * The artwork a customer wants printed on their bags — an image or a PDF. Held against the
 * customer so an order can be placed against one later without the file being sent again.
 *
 * Files go to the disk named by `MEDIA_DESIGNS_DISK`, which is **private** by default and is not
 * the disk product photos use: a product photo is the business's own marketing, while this is
 * the customer's property. `file_url` is generated per request, so on a private bucket it is a
 * signed link that expires.
 *
 * **A design's file is never replaced and never erased.** There is no endpoint that swaps the
 * bytes — a new version is a new upload — and deleting one hides it from the list while the
 * object stays, so an order printed last year can still show what was printed.
 *
 * Guarded by `customers.view` to read and `customers.manage` to change, the same pair that
 * governs the customer itself. A separate `customers.designs.manage` would only earn its place
 * the day a role appears that needs one and not the other — a delivery-only account, or an
 * outside designer given a login.
 */
class CustomerDesignController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly UploadCustomerDesign $uploadDesign,
        private readonly DeleteCustomerDesign $deleteDesign,
    ) {}

    /**
     * List a customer's designs
     *
     * Newest first, and capped — a customer holds at most fifty, so there is nothing to page.
     */
    public function index(Customer $customer): JsonResponse
    {
        return $this->success(
            CustomerDesignResource::collection($customer->designs()->get()),
        );
    }

    /**
     * Upload a design
     *
     * Send as `multipart/form-data`. Accepts pdf, jpeg, png or webp.
     *
     * **Idempotent.** Uploading a file this customer already has answers `200` with the design
     * that already exists rather than storing a second copy — a dropped connection leaves the
     * caller unable to know whether the request landed, so a retry has to be free.
     */
    public function store(StoreCustomerDesignRequest $request, Customer $customer): JsonResponse
    {
        [$design, $wasCreated] = ($this->uploadDesign)(
            customer: $customer,
            file: $request->file('file'),
            label: $request->string('label')->toString() ?: null,
            notes: $request->string('notes')->toString() ?: null,
        );

        $resource = new CustomerDesignResource($design);

        return $wasCreated
            ? $this->created($resource, 'تم رفع التصميم بنجاح')
            : $this->success($resource, 'هذا التصميم محفوظ مسبقاً');
    }

    /**
     * Rename a design
     *
     * Changes the label and the notes. The file itself cannot be swapped: an order points at a
     * design, so replacing the bytes under a stable id would change what an old order says was
     * printed.
     */
    public function update(
        UpdateCustomerDesignRequest $request,
        Customer $customer,
        CustomerDesign $design,
    ): JsonResponse {
        $design->update($request->only(['label', 'notes']));

        return $this->success(new CustomerDesignResource($design->refresh()), 'تم تحديث التصميم');
    }

    /**
     * Remove a design
     *
     * Hides it from the list. The file is kept, so an order that used it still resolves.
     */
    public function destroy(Customer $customer, CustomerDesign $design): JsonResponse
    {
        ($this->deleteDesign)($design);

        return $this->successMessage('تم حذف التصميم');
    }
}
