<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * Same shape as creating; the uniqueness rule differs, because a category keeping its own name
 * must not collide with itself, and two things about the tree can only be asked of a category
 * that already exists — see {@see after()}.
 *
 * The rules are written out in full rather than merged onto the parent's on purpose: Scramble
 * reads this method statically to build the OpenAPI request body and cannot follow an
 * `array_merge(parent::rules(), …)` call — doing that leaves the endpoint with no documented body.
 */
class UpdateProductCategoryRequest extends StoreProductCategoryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueAmongOthers()],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                // Only a root may be a parent — the one-level rule, as on creating.
                Rule::exists('product_categories', 'id')
                    ->whereNull('deleted_at')
                    ->whereNull('parent_id'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            // Changing this affects the *next* order under the heading, never one already
            // taken — see `UpdateProductCategory` and `ResolveOrderFlow`. The legacy boolean
            // beside it is translated before any rule runs, and an omitted mode keeps the stored
            // one — see `StoreProductCategoryRequest::prepareForValidation()`.
            'production_mode' => ['sometimes', Rule::enum(ProductionMode::class)],
            // Omitting it keeps the stored answer rather than handing the heading back to its
            // parent — see `StoreProductCategoryRequest::keepStored()`, which says the same of
            // every field beside it.
            'is_investable' => ['sometimes', 'nullable', 'boolean'],
            'skips_production' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The two things the rules above cannot say, because both are about *this* row.
     *
     * A category cannot be its own parent — the `exists` rule would happily accept its own id —
     * and one that already holds children cannot be filed under something, because that would
     * make its children grandchildren of a root and quietly break the one-level rule the rest of
     * the codebase relies on.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parentId = $this->input('parent_id');
                if ($parentId === null) {
                    return;
                }

                /** @var ProductCategory $category */
                $category = $this->route('product_category');

                if ((int) $parentId === $category->getKey()) {
                    $validator->errors()->add('parent_id', 'لا يمكن أن يكون التصنيف تابعاً لنفسه');

                    return;
                }

                if ($category->children()->exists()) {
                    $validator->errors()->add(
                        'parent_id',
                        'هذا التصنيف يحتوي تصنيفات فرعية، فلا يمكن جعله تابعاً لتصنيف آخر. انقل فروعه أولاً.',
                    );
                }
            },
        ];
    }

    private function nameUniqueAmongOthers(): Unique
    {
        /** @var ProductCategory $category */
        $category = $this->route('product_category');

        return Rule::unique('product_categories', 'name')->ignore($category->getKey())->withoutTrashed();
    }
}
