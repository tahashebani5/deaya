<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Actions\DeleteProductImage;
use Database\Factories\ProductImageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One photo of a product.
 *
 * Soft deleted like everything else, with one asymmetry worth knowing: the *file* is deleted for
 * real. Object storage has no `deleted_at`, and keeping every replaced photo forever would grow
 * without bound. So a restored image row would point at a missing object — which is why
 * {@see DeleteProductImage} is the only place that removes one, and
 * why nothing offers to restore it.
 */
#[UseFactory(ProductImageFactory::class)]
#[Fillable([
    'disk', 'path', 'original_filename', 'mime_type', 'size_bytes',
    'width_px', 'height_px', 'alt_text', 'is_primary', 'sort_order',
])]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Built on demand from the disk this file actually lives on.
     *
     * A disk that signs its URLs (a private S3 bucket) returns a link that expires; a public
     * disk returns a plain one. Chosen by asking the disk what it supports rather than by
     * catching a failure — the capability is knowable up front.
     */
    public function url(): string
    {
        $disk = $this->storage();

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($this->path, now()->addMinutes(config('media.temporary_url_minutes')))
            : $disk->url($this->path);
    }
}
