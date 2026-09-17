<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts a picture on a catalogue heading, replacing whatever was there.
 *
 * **The old file is deleted, unlike a customer's design.** A design is the customer's property
 * and an order printed last year still points at it; a category's picture is the business's own
 * marketing, replaced when somebody wants a better one, and nothing anywhere refers to the
 * previous version. Keeping every replaced heading image would grow without bound for no reader.
 *
 * The row is written first and the old object removed afterwards: a delete that fails leaves a
 * stray file on disk, which is storage spent — the cheaper failure — while the reverse would
 * leave a row pointing at bytes that are gone.
 */
final class SetProductCategoryImage
{
    public function __invoke(ProductCategory $category, UploadedFile $file): ProductCategory
    {
        $disk = (string) config('media.disk');
        $previous = $category->hasImage()
            ? ['disk' => (string) $category->image_disk, 'path' => (string) $category->image_path]
            : null;

        // A generated name, not the uploaded one: two uploads called "bags.png" must not
        // collide, and nobody uploading a file gets to choose a path.
        $path = $file->storeAs(
            "product-categories/{$category->getKey()}",
            Str::uuid()->toString().'.'.$file->extension(),
            ['disk' => $disk],
        );

        [$width, $height] = $this->dimensionsOf($file);

        // `forceFill`, not `update`: these four are written by this action and by nothing
        // else, so they stay off the fillable list where a request could reach them.
        $category->forceFill([
            'image_disk' => $disk,
            'image_path' => $path,
            'image_width_px' => $width,
            'image_height_px' => $height,
        ])->save();

        if ($previous !== null) {
            Storage::disk($previous['disk'])->delete($previous['path']);
        }

        return $category->refresh();
    }

    /**
     * `getimagesize` answers false rather than throwing when a file is not a readable image, so
     * the dimensions stay null instead of failing an otherwise valid upload.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensionsOf(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
