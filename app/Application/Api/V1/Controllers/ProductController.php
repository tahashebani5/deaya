<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Product\QuoteProductRequest;
use App\Application\Api\V1\Requests\Product\StoreProductRequest;
use App\Application\Api\V1\Requests\Product\UpdateProductRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Resources\PriceQuoteResource;
use App\Application\Api\V1\Resources\ProductResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Queries\ProductFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Products
 *
 * The bag catalogue. A product is a bag type; each has one or more variants (usually a size),
 * and each variant carries a list of quantity price breaks.
 *
 * Two things vary independently:
 * - **pricing_unit** — `piece` or `kilogram`. It decides what a quantity means.
 * - **pricing_mode** — `tiered` for listed prices, or `quote_on_request` for the bags whose price
 *   depends on specifications and is agreed by hand. Quoting a `quote_on_request` product is
 *   refused rather than guessed.
 */
class ProductController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    /**
     * List products
     *
     * In catalogue order. `search` matches the name or the slug.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = ProductFilters::fromArray(
            $request->only([
                'search', 'category', 'product_category_id', 'pricing_unit', 'pricing_mode', 'is_active',
            ]),
        );
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            ProductResource::collection($this->catalog->paginate($filters, $perPage)),
        );
    }

    /**
     * Create a product
     *
     * Send as `multipart/form-data`. A photo is **required** — a catalogue entry with no picture
     * is a gap in the grid, so the product is refused rather than created and left to be
     * photographed later. It becomes the product's primary image.
     *
     * Variants and their price tiers may be supplied inline, as `variants[0][label]` and
     * `variants[0][price_tiers][0][unit_price]`.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->catalog->create(
            ProductData::fromArray($request->validated()),
            $request->file('image'),
            $request->string('image_alt_text')->toString() ?: null,
        );

        return $this->created(new ProductResource($product), 'تم إضافة المنتج بنجاح');
    }

    /**
     * Get one product
     */
    public function show(Product $product): JsonResponse
    {
        return $this->success(new ProductResource($product->load(['variants.priceTiers', 'variants.stockItem', 'images', 'productCategory.parent', 'stockItemGroup'])));
    }

    /**
     * Update a product
     *
     * Sending `variants` replaces the whole set: entries with an `id` are updated, entries
     * without one are added, and any variant left out is removed along with its prices. Omit
     * `variants` entirely to leave the price list untouched.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->catalog->update($product, ProductData::fromArray($request->validated()));

        return $this->success(new ProductResource($updated), 'تم تحديث المنتج بنجاح');
    }

    /**
     * Activate or deactivate a product
     *
     * Products are never deleted — deactivating takes one off the catalogue while past orders
     * keep pointing at it.
     */
    public function setActivation(SetActivationRequest $request, Product $product): JsonResponse
    {
        $updated = $this->catalog->setActive($product, $request->boolean('is_active'));

        return $this->success(
            new ProductResource($updated),
            $updated->is_active ? 'تم تنشيط المنتج' : 'تم إلغاء تنشيط المنتج',
        );
    }

    /**
     * Price a quantity
     *
     * Returns the unit price for the quantity break that applies, the total, and — when the
     * customer has not reached the cheapest break yet — how much more would get them there.
     *
     * Refuses with 422 when the product is priced on request, or when the quantity is under the
     * product's minimum order.
     */
    public function quote(QuoteProductRequest $request, Product $product): JsonResponse
    {
        /** @var ProductVariant $variant */
        $variant = $product->variants()
            ->with('priceTiers')
            ->findOrFail($request->integer('variant_id'));

        $quote = $this->catalog->quote($product, $variant, (string) $request->input('quantity'));

        return $this->success(new PriceQuoteResource($quote));
    }

    /**
     * A product's history
     *
     * Every change to the product, its sizes, its price breaks and its photos, newest first —
     * who made it and what it was before.
     *
     * The sizes and prices are included on purpose: "who put 25*35 up to 0.850?" is the question
     * this endpoint exists to answer, and that number lives on a different table. A client
     * should not have to know that to ask.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, Product $product, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $product, $audit);
    }
}
