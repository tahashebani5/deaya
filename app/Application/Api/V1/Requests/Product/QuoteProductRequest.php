<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "What does this many of this size cost?"
 */
class QuoteProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            // Scoped to this product's own variants, so a quote cannot be built from someone
            // else's size.
            'variant_id' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $product->getKey()),
            ],
            // Numeric rather than integer: a per-kilo product is ordered by weight. Whole
            // numbers are enforced below, only where they actually apply.
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Product $product */
            $product = $this->route('product');
            $quantity = $this->input('quantity');

            if (! is_numeric($quantity) || ! $product->pricing_unit->requiresWholeQuantities()) {
                return;
            }

            // Half a shipping bag is not a thing.
            if (floor((float) $quantity) !== (float) $quantity) {
                $validator->errors()->add('quantity', 'الكمية يجب أن تكون رقماً صحيحاً للمنتجات المُسعَّرة بالقطعة');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variant_id.required' => 'المقاس مطلوب',
            'variant_id.exists' => 'المقاس المحدد لا ينتمي لهذا المنتج',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.min' => 'الكمية يجب أن تكون أكبر من صفر',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'variant_id' => 'المقاس',
            'quantity' => 'الكمية',
        ];
    }

    public function pricingUnit(): PricingUnit
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product->pricing_unit;
    }
}
