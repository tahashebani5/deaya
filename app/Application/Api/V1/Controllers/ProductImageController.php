<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Product\UpdateProductImageRequest;
use App\Application\Api\V1\Requests\Product\UploadProductImageRequest;
use App\Application\Api\V1\Resources\ProductImageResource;
use App\Application\Controller;
use App\Domain\Catalog\Actions\DeleteProductImage;
use App\Domain\Catalog\Actions\SetPrimaryProductImage;
use App\Domain\Catalog\Actions\UploadProductImage;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Product images
 *
 * Extra photos for a catalogue entry. The first one arrives with the product itself — a product
 * cannot be created without a picture — and this is where the rest are added.
 *
 * The first one uploaded becomes the product's primary image automatically, and a product can
 * only ever have one. It can never be left with none: deleting the last photo is refused.
 *
 * Files are written to the disk named by `MEDIA_DISK` — the `public` disk locally, S3 in
 * production. Each image remembers the disk it was written to, so switching over affects only
 * new uploads and needs no migration. URLs are generated per request rather than stored, which
 * keeps them correct after a move and lets a private bucket return a signed link.
 */
class ProductImageController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly UploadProductImage $uploadImage,
        private readonly SetPrimaryProductImage $setPrimaryImage,
        private readonly DeleteProductImage $deleteImage,
    ) {}

    /**
     * Upload an image
     *
     * Send as `multipart/form-data`. Accepts jpeg, png or webp.
     */
    public function store(UploadProductImageRequest $request, Product $product): JsonResponse
    {
        $image = ($this->uploadImage)(
            product: $product,
            file: $request->file('image'),
            altText: $request->string('alt_text')->toString() ?: null,
            makePrimary: $request->boolean('is_primary'),
        );

        return $this->created(new ProductImageResource($image), 'تم رفع الصورة بنجاح');
    }

    /**
     * Update an image
     *
     * Changes the caption, the ordering, or promotes this image to be the product's primary.
     * The file itself cannot be swapped — upload a new image and delete the old one, so a URL
     * already handed out never starts pointing at different content.
     */
    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): JsonResponse
    {
        $attributes = array_filter(
            $request->only(['alt_text', 'sort_order']),
            fn (string $key) => $request->has($key),
            ARRAY_FILTER_USE_KEY,
        );

        if ($attributes !== []) {
            $image->update($attributes);
        }

        if ($request->boolean('is_primary')) {
            ($this->setPrimaryImage)($product, $image);
        }

        return $this->success(new ProductImageResource($image->refresh()), 'تم تحديث الصورة بنجاح');
    }

    /**
     * Delete an image
     *
     * Removes the record and the stored file. If it was the primary image, the next one takes
     * its place so the product is never left without a thumbnail.
     *
     * Refuses with 422 when it is the product's only photo. To replace it, upload the new one
     * first and then delete this — at no point does the product have nothing.
     */
    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        ($this->deleteImage)($product, $image);

        return $this->successMessage('تم حذف الصورة بنجاح');
    }
}
