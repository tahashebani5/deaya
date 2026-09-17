<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Customer\Enums\DesignKind;
use Database\Factories\CustomerDesignFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A customer's artwork — what gets printed on their bags.
 *
 * Built on the same media layer as {@see ProductImage}: the disk and the path are recorded per
 * row and no URL is ever stored, so moving to S3 is a config change with no migration.
 *
 * **One rule is inverted, and it is the whole reason this is a separate model.** A product photo
 * is deleted for real when it is removed, because object storage has no `deleted_at` and keeping
 * every replaced photo would grow without bound. A design is the opposite: **the file is never
 * removed.** Deleting one hides the row from the picker and leaves the object exactly where it
 * is, because an order printed last year must still be able to show what was printed — and the
 * colleague tidying up the list has no idea which designs an old order is pointing at.
 *
 * That is a commitment as much as a design: this model has no way to erase a customer's file.
 * If one is ever asked for — it is their intellectual property — that is a hard-delete path to
 * add deliberately, behind its own permission.
 */
#[UseFactory(CustomerDesignFactory::class)]
#[Fillable([
    'disk', 'path', 'original_filename', 'mime_type', 'kind',
    'size_bytes', 'checksum', 'width_px', 'height_px', 'label', 'notes',
])]
class CustomerDesign extends Model
{
    /** @use HasFactory<CustomerDesignFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => DesignKind::class,
            'size_bytes' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Built on demand from the disk this file actually lives on.
     *
     * A design sits on a **private** disk, unlike a product photo: a product image is the
     * business's own marketing, while this is the customer's property and one leaked path is a
     * competitor holding their print file. So in production this returns a signed link that
     * expires; the capability is asked of the disk rather than assumed.
     */
    public function url(): string
    {
        $disk = $this->storage();

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($this->path, now()->addMinutes(config('media.temporary_url_minutes')))
            : $disk->url($this->path);
    }

    /** A name to show when the uploader did not give one. */
    public function displayName(): string
    {
        return $this->label ?? $this->original_filename ?? "تصميم #{$this->getKey()}";
    }
}
