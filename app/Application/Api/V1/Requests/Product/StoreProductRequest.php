<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use App\Application\Rules\CategoryMustBeALeaf;
use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a product. **`multipart/form-data`, not JSON** — a photo is required and arrives with
 * the rest of the body, so the two cannot be separate requests.
 *
 * See PRODUCT-IMAGE-REQUIRED-DESIGN.md for why the photo is not uploaded afterwards instead.
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Form encoding has no types: every value arrives as a string, and `(bool) "false"` is
     * `true` in PHP. Without this a product created with `is_active=false` would come back
     * active, and nothing downstream would look wrong enough to investigate.
     *
     * Only the booleans need it. The numeric rules accept numeric strings, and every other
     * field is a string already.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge(['is_active' => $this->boolean('is_active')]);
        }

        $variants = $this->input('variants');

        if (! is_array($variants)) {
            return;
        }

        foreach ($variants as $index => $variant) {
            if (is_array($variant) && array_key_exists('is_active', $variant)) {
                $variants[$index]['is_active'] = filter_var(
                    $variant['is_active'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
            }
        }

        $this->merge(['variants' => $variants]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The one field that makes this endpoint multipart. `image` on top of the mime list
            // verifies the file really is an image rather than trusting a renamed extension or a
            // client-supplied content type — the same pair UploadProductImageRequest uses.
            'image' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('media.product_images.mimes')),
                'max:'.config('media.product_images.max_kilobytes'),
            ],
            'image_alt_text' => ['nullable', 'string', 'max:255'],

            // Optional, and generated from the name when it is left out — see
            // {@see GenerateProductSlug}. Asking a shop to invent a unique lowercase Latin string
            // for a product called أكياس الشحن is asking them to do the server's arithmetic.
            //
            // Still fully validated when it *is* sent: an import may carry a deliberate slug, and
            // optional is not the same as unchecked.
            'slug' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('products', 'slug')->withoutTrashed()],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['required', 'string', 'max:255'],

            // **The heading a product is filed under must be a leaf.** A category holding
            // subheadings is a heading, not a slot — filing under both would make «كم منتجاً تحت
            // أكياس؟» two different questions. The check is a rule object so the refusal lands
            // on the field it is about, beside the picker that produced it, rather than as a
            // sentence from a controller about the request as a whole.
            'product_category_id' => [
                'required', 'integer',
                Rule::exists('product_categories', 'id')->whereNull('deleted_at'),
                new CategoryMustBeALeaf,
            ],
            // What the product is made of — «التصنيف». Optional, and the reason most
            // products never need to name a shelf size by size: with a material set, every
            // variant resolves to that material's item at the same size, created on the spot if
            // the material has not reached it yet. An explicit `variants[].stock_item_id` still
            // wins over it.
            'stock_item_group_id' => [
                'nullable', 'integer',
                Rule::exists('stock_item_groups', 'id')->whereNull('deleted_at'),
            ],

            'pricing_unit' => ['required', Rule::enum(PricingUnit::class)],

            // What the warehouse counts this in, if it differs from `pricing_unit` — a product
            // bought in by weight and sold by the piece needs both. Left out entirely on the
            // common path, where the two agree; see {@see ProductData::fromArray()} for the
            // default. Never on `UpdateProductRequest`: past creation, only
            // `PATCH products/{product}/stock-unit` may change it.

            'pricing_mode' => ['required', Rule::enum(PricingMode::class)],

            'min_order_quantity' => ['required', 'numeric', 'min:0.001'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            // A size and its price list. Sizes are optional here so a quote-only product can be
            // created before its sizes are known.
            'variants' => ['sometimes', 'array'],
            // Which shelf this size draws from. Nullable on purpose: a quote-only size is never
            // stocked, and requiring one here would teach whoever fills the form to point at an
            // unrelated row to get past it. Every path that moves stock refuses an unlinked size
            // by name instead.
            'variants.*.stock_item_id' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->whereNull('deleted_at')],
            'variants.*.label' => ['required', 'string', 'max:60', 'distinct'],
            'variants.*.width_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'variants.*.height_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            // **سعر التكلفة — only under a «وسيط» heading**, and refused elsewhere by
            // {@see rejectCostPriceOnGoodsWeMakeOurselves()} rather than by a rule here: the
            // answer depends on the category the same payload names, which a field rule cannot
            // see. Writing it needs `products.manage` like every other catalogue number; *seeing*
            // it needs `products.view_cost`, which the clerk taking orders does not hold.
            'variants.*.cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.999'],

            'variants.*.price_tiers' => ['sometimes', 'array'],
            'variants.*.price_tiers.*.min_quantity' => ['required', 'numeric', 'min:0.001'],
            'variants.*.price_tiers.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Two rules the field-level rules cannot see, because each depends on more than one field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectFractionalMinimumForPieces($validator);
            $this->rejectPricesOnQuoteOnlyProducts($validator);
            $this->rejectCostPriceOnGoodsWeMakeOurselves($validator);
        });
    }

    /**
     * A cost price belongs to a وسيط product and to nothing else.
     *
     * **The refusal, not a silent drop.** Storing the number anyway would leave a figure on the
     * catalogue that nothing reads and that contradicts the costing this product actually gets —
     * `order_items.material_cost` from the shelf, plus the labour and overhead rates — and the
     * day somebody built a margin report from it, it would answer confidently and wrongly.
     *
     * Read through the *effective* mode so a size under «وسيط ورقي» beneath «وسيط» is allowed one
     * — the same inheritance every other reader of a heading uses.
     */
    private function rejectCostPriceOnGoodsWeMakeOurselves(Validator $validator): void
    {
        $priced = array_filter(
            (array) $this->input('variants', []),
            fn ($variant) => is_array($variant)
                && ($variant['cost_price'] ?? null) !== null
                && $variant['cost_price'] !== '',
        );

        if ($priced === []) {
            return;
        }

        $category = ProductCategory::query()
            ->with('parent')
            ->find($this->input('product_category_id'));

        if ($category?->productionMode()->hasCostPrice() === true) {
            return;
        }

        foreach (array_keys($priced) as $index) {
            $validator->errors()->add(
                "variants.{$index}.cost_price",
                'سعر التكلفة لا يُسجَّل إلا على المنتجات الوسيطة',
            );
        }
    }

    private function rejectFractionalMinimumForPieces(Validator $validator): void
    {
        if ($this->input('pricing_unit') !== PricingUnit::Piece->value) {
            return;
        }

        $minimum = $this->input('min_order_quantity');

        if (is_numeric($minimum) && floor((float) $minimum) !== (float) $minimum) {
            $validator->errors()->add('min_order_quantity', 'الحد الأدنى للمنتجات المُسعَّرة بالقطعة يجب أن يكون رقماً صحيحاً');
        }
    }

    /**
     * A quote-only product must not carry prices — publishing a price for something the
     * catalogue says is priced on request is exactly the contradiction this guards against.
     */
    private function rejectPricesOnQuoteOnlyProducts(Validator $validator): void
    {
        if ($this->input('pricing_mode') !== PricingMode::QuoteOnRequest->value) {
            return;
        }

        foreach ((array) $this->input('variants', []) as $index => $variant) {
            if (! empty($variant['price_tiers'])) {
                $validator->errors()->add(
                    "variants.{$index}.price_tiers",
                    'لا يمكن إضافة أسعار لمنتج سعره حسب الطلب',
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'صورة المنتج مطلوبة',
            'image.image' => 'الملف المرفوع ليس صورة صالحة',
            'image.mimes' => 'الصيغ المسموحة: jpeg، jpg، png، webp',
            'image.max' => 'حجم الصورة أكبر من الحد المسموح',
            'slug.required' => 'المعرف مطلوب',
            'slug.regex' => 'المعرف يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات فقط',
            'slug.unique' => 'المعرف مستخدم مسبقاً',
            'name.required' => 'اسم المنتج مطلوب',
            'pricing_unit.required' => 'وحدة التسعير مطلوبة',
            'pricing_mode.required' => 'طريقة التسعير مطلوبة',
            'min_order_quantity.required' => 'الحد الأدنى للطلب مطلوب',
            'min_order_quantity.min' => 'الحد الأدنى للطلب يجب أن يكون أكبر من صفر',
            'variants.*.label.required' => 'اسم المقاس مطلوب',
            'variants.*.price_tiers.*.min_quantity.required' => 'الكمية الأدنى للشريحة مطلوبة',
            'variants.*.price_tiers.*.unit_price.required' => 'سعر الوحدة مطلوب',
            'variants.*.price_tiers.*.unit_price.min' => 'سعر الوحدة لا يمكن أن يكون سالباً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => 'صورة المنتج',
            'image_alt_text' => 'النص البديل للصورة',
            'slug' => 'المعرف',
            'name' => 'اسم المنتج',
            'description' => 'الوصف',
            'features' => 'المميزات',
            'product_category_id' => 'التصنيف',
            'pricing_unit' => 'وحدة التسعير',
            'pricing_mode' => 'طريقة التسعير',
            'min_order_quantity' => 'الحد الأدنى للطلب',
            'variants' => 'المقاسات',
        ];
    }
}
