<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Requests\Product\QuoteProductRequest;
use App\Application\Api\V1\Resources\Client\ClientProductCategoryResource;
use App\Application\Api\V1\Resources\Client\ClientProductResource;
use App\Application\Api\V1\Resources\PriceQuoteResource;
use App\Application\Controller;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Queries\ProductCategoryFilters;
use App\Domain\Catalog\Queries\ProductFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catalogue
 *
 * What the customer app can buy, and what a quantity of it costs.
 *
 * **Read-only, and live-only.** A product is deactivated rather than deleted, so the staff list
 * can ask for the retired half of the catalogue and this one cannot: `is_active` is fixed by
 * {@see self::index()} rather than read from the query string, so a customer sending
 * `?is_active=0` gets the live catalogue and not a tour of what the shop has stopped selling.
 *
 * **Nothing here reaches for a permission**, and it could not if it wanted to: a `Customer` has
 * no `can()`. What a customer may see is decided by which fields the client resources list —
 * see {@see ClientProductResource} — rather than by a gate lookup. That is the rule for every
 * resource under `Resources/Client`.
 */
class CatalogController extends Controller
{
    use ResponseTrait;

    /** What one page of the catalogue holds when the app does not say. */
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * The catalogue
     *
     * Live products only, newest first. `search` matches the name; `product_category_id` narrows
     * to one heading.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = new ProductFilters(
            search: ($search = trim((string) $request->query('search', ''))) !== '' ? $search : null,
            productCategoryId: ($id = (int) $request->query('product_category_id', 0)) > 0 ? $id : null,
            // **Fixed here, never read from the request.** This is the line that keeps the
            // retired catalogue out of the app; `ProductFilters::fromArray()` would have taken
            // `is_active` from the query string.
            isActive: true,
        );

        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        return $this->successWithPagination(
            ClientProductResource::collection($this->catalog->paginate($filters, $perPage)),
        );
    }

    /**
     * One product
     *
     * With its sizes and their price breaks.
     */
    public function show(Product $product): JsonResponse
    {
        $live = $this->liveOrFail($product);

        return $this->success(new ClientProductResource(
            $live->load(['variants.priceTiers', 'images', 'productCategory']),
        ));
    }

    /**
     * Price a quantity
     *
     * **The price comes from here rather than from a calculation in the app**, so the number the
     * customer is shown and the number written to the order come from the same code. Answers the
     * unit price for the break this quantity reaches, the total, and how many more units would
     * reach the next break.
     */
    public function quote(QuoteProductRequest $request, Product $product): JsonResponse
    {
        $live = $this->liveOrFail($product);

        /** @var ProductVariant $variant */
        $variant = $live->variants()->with('priceTiers')->findOrFail($request->integer('variant_id'));

        $quote = $this->catalog->quote($live, $variant, (string) $request->input('quantity'));

        return $this->success(new PriceQuoteResource($quote));
    }

    /**
     * The catalogue's headings
     *
     * For the filter chips. Live headings only, and never paged — there are a handful.
     */
    public function categories(): JsonResponse
    {
        $categories = $this->catalog->paginateCategories(
            new ProductCategoryFilters(isActive: true),
            perPage: self::MAX_PER_PAGE,
        );

        return $this->success(ClientProductCategoryResource::collection($categories->items()));
    }

    /**
     * A product the shop still sells, or a 404.
     *
     * **A 404 rather than a 403**, and the difference matters: «هذا المنتج موجود ولا يُباع لك»
     * is a fact about the shop's catalogue that a customer has no business learning. A product
     * taken off the catalogue is, from the app's side, simply not there.
     *
     * Route-model binding resolves the row before this runs, so the check is here rather than in
     * the route — the binding has no way to express «and active».
     */
    private function liveOrFail(Product $product): Product
    {
        if (! $product->is_active) {
            throw new NotFoundHttpException;
        }

        return $product;
    }
}
