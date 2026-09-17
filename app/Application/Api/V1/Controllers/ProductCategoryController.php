<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Product\ReorderProductCategoriesRequest;
use App\Application\Api\V1\Requests\Product\SetProductCategoryImageRequest;
use App\Application\Api\V1\Requests\Product\StoreProductCategoryRequest;
use App\Application\Api\V1\Requests\Product\UpdateProductCategoryRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Resources\ProductCategoryResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\DTOs\ProductCategoryData;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Queries\ProductCategoryFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product categories
 *
 * التصنيفات — the headings the catalogue is organised under: أكياس, علب وكراتين التغليف,
 * ستيكرات ومطبوعات أخرى. A short list the business curates, picked from when a product is
 * recorded, and filtered by on the products screen.
 *
 * **The only thing that classifies a product.** A `category` field carrying مطبوعة/سادة used to
 * sit beside it; it drove nothing, so it became two rows in this table. See
 * PRODUCT-CATEGORIES.md.
 *
 * Reading needs `products.view` and changing the list needs `products.manage`: no pair of its
 * own, because whoever may read products needs their categories to read them by, and whoever
 * maintains products maintains the headings they sit under. Both are declared on the routes, so
 * the guard on an endpoint is visible without opening this file.
 */
class ProductCategoryController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * List product categories
     *
     * In the catalogue's own order, then by name. `search` matches the name; `is_active=1` is
     * what a picker asks for, while the management screen leaves it off and sees both.
     *
     * `leaf_only=1` narrows it to the headings a product may actually be filed under — one
     * holding subheadings is a heading, not a slot. Each row carries `products_count`,
     * `children_count` and `total_products_count`.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = ProductCategoryFilters::fromArray(
            $request->only(['search', 'is_active', 'leaf_only']),
        );
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            ProductCategoryResource::collection($this->catalog->paginateCategories($filters, $perPage)),
        );
    }

    /**
     * Create a product category
     */
    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        $category = $this->catalog->createCategory(ProductCategoryData::fromArray($request->validated()));

        return $this->created(new ProductCategoryResource($category), 'تم إضافة التصنيف بنجاح');
    }

    /**
     * Get one product category
     */
    public function show(ProductCategory $productCategory): JsonResponse
    {
        return $this->success(
            new ProductCategoryResource(
                $productCategory->loadCount(['products', 'children'])->load('parent:id,name'),
            ),
        );
    }

    /**
     * Update a product category
     *
     * Renaming applies everywhere at once, including to the products already under it: this is a
     * label, not a snapshot of where a product sat on some particular day.
     */
    public function update(
        UpdateProductCategoryRequest $request,
        ProductCategory $productCategory,
    ): JsonResponse {
        $updated = $this->catalog->updateCategory(
            $productCategory,
            ProductCategoryData::fromArray($request->validated()),
        );

        return $this->success(new ProductCategoryResource($updated), 'تم تحديث التصنيف بنجاح');
    }

    /**
     * Activate or deactivate a product category
     *
     * The ordinary way to retire one. It disappears from the pickers; every product already
     * under it keeps saying so.
     */
    public function setActivation(
        SetActivationRequest $request,
        ProductCategory $productCategory,
    ): JsonResponse {
        $isActive = $request->boolean('is_active');
        $updated = $this->catalog->setCategoryActive($productCategory, $isActive);

        return $this->success(
            new ProductCategoryResource($updated),
            $isActive ? 'تم تفعيل التصنيف' : 'تم إيقاف التصنيف',
        );
    }

    /**
     * Delete a product category
     *
     * Only for the row that should never have existed. A category any product is recorded under
     * is refused with 422 — deactivate it instead.
     */
    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        $this->catalog->deleteCategory($productCategory);

        return $this->successMessage('تم حذف التصنيف بنجاح');
    }

    /**
     * Reorder the categories
     *
     * Send `ids` in the order they should appear. **The whole order in one call**, because a
     * drag moves one card and renumbers everything after it — a request per moved row would be
     * a burst of writes where one is needed, and a dropped connection halfway would leave the
     * list in an order nobody chose.
     *
     * Ids left out keep the position they had, so a screen showing one page can reorder that
     * page without claiming anything about the rest.
     */
    public function reorder(ReorderProductCategoriesRequest $request): JsonResponse
    {
        /** @var list<int> $ids */
        $ids = array_map(intval(...), (array) $request->validated('ids'));

        $this->catalog->reorderCategories($ids);

        return $this->successMessage('تم حفظ ترتيب التصنيفات');
    }

    /**
     * Set a category's image
     *
     * Send as `multipart/form-data`. **Replaces whatever was there**, and deletes the old file:
     * nothing points at a heading's picture the way an order points at a design, so a replaced
     * one is bytes nobody will read again.
     */
    public function setImage(
        SetProductCategoryImageRequest $request,
        ProductCategory $productCategory,
    ): JsonResponse {
        $updated = $this->catalog->setCategoryImage($productCategory, $request->file('image'));

        return $this->success(
            new ProductCategoryResource($updated->loadCount(['products', 'children'])),
            'تم حفظ صورة التصنيف',
        );
    }

    /**
     * Remove a category's image
     *
     * Idempotent: a heading with no picture is already in the state this asks for, so a second
     * tap after a dropped connection is not an error.
     */
    public function removeImage(ProductCategory $productCategory): JsonResponse
    {
        $updated = $this->catalog->removeCategoryImage($productCategory);

        return $this->success(
            new ProductCategoryResource($updated->loadCount(['products', 'children'])),
            'تم حذف صورة التصنيف',
        );
    }

    /**
     * A product category's history
     *
     * Who added it, who renamed it, who retired it — newest first. Filter with `event`,
     * `causer_id`, `from` and `to`.
     */
    public function logs(
        ActivityLogFilterRequest $request,
        ProductCategory $productCategory,
        AuditService $audit,
    ): JsonResponse {
        return $this->auditTrailResponse($request, $productCategory, $audit);
    }
}
