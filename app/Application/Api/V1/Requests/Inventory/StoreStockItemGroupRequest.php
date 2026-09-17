<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockItemGroupRequest extends FormRequest
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
        return [
            // Unique across materials, and that is load-bearing rather than tidy: a grouped stock
            // item carries its group's name, and `stock_items` is unique on (name, size) — so two
            // materials sharing a name would fight over the same shelf and the resolver would have
            // no way to say which one a product meant.
            //
            // `withoutTrashed` matches the partial index in the database: a deleted material
            // releases its name, and the rule has to agree with the constraint or one of them
            // produces a 500 where the other produces a message.
            'name' => [
                'required', 'string', 'min:2', 'max:255',
                Rule::unique('stock_item_groups', 'name')->withoutTrashed(),
            ],

            // What a size created under this material starts out counted in.
            'default_unit' => ['required', Rule::enum(PricingUnit::class)],

            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم التصنيف مطلوب',
            'name.min' => 'اسم التصنيف قصير جداً',
            'name.max' => 'اسم التصنيف طويل جداً',
            'name.unique' => 'يوجد تصنيف بنفس الاسم',
            'default_unit.required' => 'وحدة التخزين الافتراضية مطلوبة',
            'default_unit.enum' => 'وحدة التخزين الافتراضية غير صحيحة',
            'description.max' => 'الوصف طويل جداً',
            'sort_order.integer' => 'الترتيب يجب أن يكون رقماً صحيحاً',
            'sort_order.min' => 'الترتيب لا يمكن أن يكون سالباً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم التصنيف',
            'default_unit' => 'وحدة التخزين الافتراضية',
            'description' => 'الوصف',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
        ];
    }
}
