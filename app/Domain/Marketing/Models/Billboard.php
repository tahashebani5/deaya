<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Database\Factories\BillboardFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A banner on the customer app's home screen.
 *
 * **Marketing is its own context** rather than a corner of `Catalog` or `Settings`. A banner is
 * not a product — it may point at one, at a link, or at nothing — and it is not a setting, which
 * is a single row of the company's defaults. What it is, is the first of the things this shop
 * says to its customers rather than about its work, and the next such thing (a promotion, a
 * push campaign) belongs beside it.
 *
 * **Deletable, unlike almost everything else here.** A customer and a product are deactivated
 * because orders point at them forever; nothing points at a poster. So a banner put up by
 * mistake comes down, and the rule that makes the rest of this schema append-only does not need
 * to bind it.
 *
 * The media columns are `product_images`' layer exactly — disk and path per row, no URL stored —
 * and on the **public** disk: this is the business's own marketing, so a signed link that
 * expires would be a banner that stops loading halfway through a campaign.
 */
#[UseFactory(BillboardFactory::class)]
#[Fillable([
    'title', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes',
    'width_px', 'height_px', 'product_id', 'external_url',
    'sort_order', 'is_active', 'starts_at', 'ends_at',
])]
class Billboard extends Model
{
    /** @use HasFactory<BillboardFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'size_bytes' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The banners showing right now — the only ones the customer app is ever sent.
     *
     * **A scope rather than a filter in the controller**, because «معروض الآن» is a fact about a
     * banner and the same three conditions would otherwise be restated at every call site. The
     * management list deliberately does *not* use it: a banner you cannot see is one you cannot
     * fix, so staff get every row, scheduled or expired or switched off.
     *
     * A null on either end of the window means «open», which is the ordinary case — most banners
     * run until somebody takes them down. Writing it as `whereNull ... orWhere` inside a grouped
     * closure matters: flattened, the `orWhere` would escape the group and hand back every
     * inactive banner whose start date happens to have passed.
     *
     * @param  Builder<Billboard>  $query
     */
    public function scopeShowingNow(Builder $query): void
    {
        $now = now();

        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * The product a tap opens, when it opens one.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Built on demand from the disk this file actually lives on, never stored — the rule the
     * whole media layer follows, so a move to S3 needs no migration.
     */
    public function imageUrl(): string
    {
        return $this->storage()->url($this->path);
    }

    /** A name to show when nobody gave the banner one. */
    public function displayName(): string
    {
        return $this->title ?? $this->original_filename ?? "لوحة #{$this->id}";
    }
}
