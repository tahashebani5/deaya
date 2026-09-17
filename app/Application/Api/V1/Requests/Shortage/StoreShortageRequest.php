<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use App\Domain\Catalog\Enums\PricingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Writing a shortage down by hand.
 *
 * **The product is optional and the name is not**, which is the decision this form exists to
 * express. What gets written down manually is very often something the catalogue has never heard
 * of, and a required product link makes an employee pick the nearest wrong row to get past the
 * field — putting a wrong answer in the column that answers «كم مرة نقص هذا المنتج؟». See
 * SHORTAGES-DESIGN §٢٫١.
 *
 * Nothing here can create an order-born shortage. Those are the reconciliation's alone — an
 * endpoint that could make one would write a row no order knows about, which the next sync would
 * then delete as an orphan.
 */
class StoreShortageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(PricingUnit::class)],
            // Three places, and the `gt:0` is the readable half of a rule the database also
            // holds as a CHECK — RULES §8.
            'required_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],

            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            // Not merely "a variant that exists": one that belongs to the product named beside
            // it, or the shortage would report a size of something else entirely.
            'product_variant_id' => [
                'nullable',
                'integer',
                'required_with:product_id',
                Rule::exists('product_variants', 'id')
                    ->where('product_id', $this->input('product_id')),
            ],

            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم النقص مطلوب',
            'unit.required' => 'الوحدة مطلوبة',
            'unit.in' => 'الوحدة غير معروفة',
            'required_quantity.required' => 'الكمية الناقصة مطلوبة',
            'required_quantity.gt' => 'الكمية الناقصة يجب أن تكون أكبر من صفر',
            'required_quantity.decimal' => 'الكمية تقبل ثلاث خانات عشرية على الأكثر',
            'product_variant_id.required_with' => 'المقاس مطلوب عند اختيار منتج',
            'product_variant_id.exists' => 'المقاس لا يتبع المنتج المختار',
            'assigned_to_user_id.exists' => 'الموظف غير موجود',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم النقص',
            'unit' => 'الوحدة',
            'required_quantity' => 'الكمية الناقصة',
            'product_id' => 'المنتج',
            'product_variant_id' => 'المقاس',
            'assigned_to_user_id' => 'الموظف المسؤول',
            'description' => 'الوصف',
        ];
    }
}
