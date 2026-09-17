<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\ProductionMode;

final readonly class ProductCategoryData
{
    public function __construct(
        public string $name,
        /**
         * The heading this one sits under, or null when it is one in its own right.
         *
         * Only a root may be named here — the one-level rule lives in the request, where it can
         * be explained rather than merely enforced.
         */
        public ?int $parentId = null,
        /** The line the catalogue prints under the heading. Null until somebody writes one. */
        public ?string $description = null,
        /** A category is offered the moment it is created; hiding one is a later, deliberate act. */
        public bool $isActive = true,
        /** Where it sits in the catalogue. Equal values fall back to the name. */
        public int $sortOrder = 0,
        /**
         * How goods under this heading come to exist — see `ProductCategory::productionMode()`.
         *
         * Defaults to `in_house`, and that is the safe direction: a heading nobody has thought
         * about yet sends its orders down the road every order took before this existed.
         *
         * **Always the modern key by the time it reaches here.** A shipped app still writes the
         * boolean `skips_production` this replaced, and the request translates it — see
         * `StoreProductCategoryRequest::prepareForValidation()`. The domain speaks one dialect.
         */
        public ProductionMode $productionMode = ProductionMode::InHouse,
        /**
         * Whether a deal may be opened against the shelves under this heading — see
         * `ProductCategory::isInvestable()`.
         *
         * **Null is an answer, not a missing one**: «اسأل الأب». A heading nobody has decided
         * about is not investable, and a subheading left at null takes its parent's answer —
         * which is why this is `?bool` and not a `bool` defaulting to false. An update that
         * mentions nothing arrives here holding what is stored; the request sees to that,
         * because by this point a missing key and an explicit null read the same.
         */
        public ?bool $isInvestable = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array crosses into the domain.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        // Trimmed here rather than at the boundary: a name with a trailing space is a second
        // «أكياس» as far as the unique index is concerned, and nobody would see why.
        $description = trim((string) ($validated['description'] ?? ''));

        return new self(
            name: trim((string) $validated['name']),
            // Null is «رئيسي», and it has to be sent to mean it. An *absent* key no longer
            // reaches here on an update: the request fills it from the stored row first, because
            // the build already in people's hands renames without it and every subheading it
            // touched came back a root. See `StoreProductCategoryRequest::keepStored()`.
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            description: $description !== '' ? $description : null,
            isActive: (bool) ($validated['is_active'] ?? true),
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            productionMode: isset($validated['production_mode'])
                ? ProductionMode::from((string) $validated['production_mode'])
                : ProductionMode::InHouse,
            // `isset` is deliberate and reads null and absent alike: both mean «اسأل الأب».
            isInvestable: isset($validated['is_investable']) ? (bool) $validated['is_investable'] : null,
        );
    }
}
