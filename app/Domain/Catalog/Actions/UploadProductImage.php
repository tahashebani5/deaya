<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores an uploaded photo and records it against the product.
 */
final class UploadProductImage
{
    public function __construct(private readonly SetPrimaryProductImage $setPrimary) {}

    public function __invoke(
        Product $product,
        UploadedFile $file,
        ?string $altText = null,
        bool $makePrimary = false,
    ): ProductImage {
        $disk = (string) config('media.disk');

        // A generated name, not the uploaded one: two customers uploading "logo.png" must not
        // collide, and an attacker must not get to choose a path.
        $path = $file->storeAs(
            "products/{$product->getKey()}",
            Str::uuid()->toString().'.'.$file->extension(),
            ['disk' => $disk],
        );

        // getimagesize returns false rather than throwing when a file is not a readable image,
        // so dimensions stay null instead of failing an otherwise valid upload.
        [$width, $height] = $this->dimensionsOf($file);

        // The first photo of a product becomes its primary one — a catalogue entry with images
        // but no thumbnail would just be a gap in the grid.
        $shouldBePrimary = $makePrimary || ! $product->images()->exists();

        return DB::transaction(function () use ($product, $disk, $path, $file, $altText, $width, $height, $shouldBePrimary): ProductImage {
            $image = $product->images()->create([
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'width_px' => $width,
                'height_px' => $height,
                'alt_text' => $altText,
                'is_primary' => false,
                'sort_order' => (int) $product->images()->max('sort_order') + 1,
            ]);

            if ($shouldBePrimary) {
                ($this->setPrimary)($product, $image);
            }

            return $image->refresh();
        });
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensionsOf(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
