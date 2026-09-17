<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\DeleteProductImage;
use App\Domain\Catalog\Actions\UploadProductImage;
use App\Domain\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Http\UploadedFile;

/**
 * Gives every product that has no photo a copy of the placeholder one.
 *
 * Written for the catalogue that predates the photo requirement: the rule now refuses a product
 * without a picture, but the products already in the table were created before it existed.
 *
 * **A copy per product, not one shared path.** {@see DeleteProductImage}
 * removes the file from disk for real, so a single object shared by twelve products would mean
 * one deletion leaving eleven rows pointing at nothing.
 *
 * Idempotent: a product that already has any photo is skipped, so re-running changes nothing.
 */
class BackfillProductImages extends Command
{
    use ConfirmableTrait;

    protected $signature = 'catalog:backfill-images
                            {--source= : The image to copy; defaults to the bundled placeholder}
                            {--force : Skip the confirmation prompt outside local}';

    protected $description = 'Give every product without a photo a copy of the placeholder image';

    public function handle(UploadProductImage $uploadImage): int
    {
        // This writes rows and files. On anything but local it asks first, and `--force` is the
        // deliberate answer rather than the default.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $source = $this->sourcePath();

        if (! is_file($source)) {
            $this->error("Source image not found: {$source}");

            return self::FAILURE;
        }

        // `doesntHave` rather than fetching everything and filtering: the skip is the whole
        // idempotency guarantee, and doing it in SQL means a re-run reads almost nothing.
        $products = Product::query()->doesntHave('images')->get();

        if ($products->isEmpty()) {
            $this->info('Every product already has a photo. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Backfilling {$products->count()} product(s) from ".basename($source).'…');

        foreach ($products as $product) {
            // A fresh UploadedFile per product. `storeAs` reads the source and writes a new
            // object rather than moving it, so the placeholder survives all twelve passes.
            $image = ($uploadImage)($product, $this->fileFrom($source));

            $this->line("  {$product->code}  {$product->name}  →  {$image->path}");
        }

        $this->info("Done. {$products->count()} product(s) now have a photo.");

        return self::SUCCESS;
    }

    private function sourcePath(): string
    {
        $option = $this->option('source');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        // Under database/seeders/data and not storage/app/public, because that directory is
        // ignored whole (`*` in its .gitignore) and a copy there does not survive a clone.
        return database_path('seeders/data/default-product-image.png');
    }

    /**
     * The placeholder dressed as an upload, so the one path that stores a product photo is the
     * one this command uses too — same naming, same primary-image promotion, same dimensions.
     */
    private function fileFrom(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            basename($path),
            mime_content_type($path) ?: 'image/png',
            null,
            // Test mode: skips the is_uploaded_file() check, which only ever passes for a file
            // that arrived over HTTP. Nothing here is trusting client input — the path is ours.
            true,
        );
    }
}
