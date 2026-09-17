<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Catalog\Models\ProductCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A product may only be filed under a heading that holds no subheadings.
 *
 * **A heading with children is a heading, not a slot.** Allowing both would make «كم منتجاً تحت
 * أكياس؟» two different questions — «مباشرة» and «بالمجموع» — and every count on every screen
 * would have to say which one it meant. Filing under the leaf keeps one number true.
 *
 * A rule object rather than a check in the controller: the refusal then lands on the field it is
 * about, in Arabic, beside the picker that produced it.
 */
final class CategoryMustBeALeaf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A missing or unknown id is somebody else's complaint — `required` and `exists` have
        // already said it, and repeating it here would put two sentences under one field.
        $category = ProductCategory::query()->find($value);

        if ($category === null) {
            return;
        }

        if ($category->children()->exists()) {
            $fail("«{$category->name}» تصنيف رئيسي يحتوي تصنيفات فرعية. اختر أحد فروعه.");
        }
    }
}
