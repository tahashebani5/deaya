<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Models\ManufacturingCostRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new standard rate for one cost type — specific to one size, specific to a whole product, or,
 * with both left out, the fallback everything without a rate of its own is costed at.
 */
class StoreManufacturingCostRateRequest extends FormRequest
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
            // Omitted entirely, not merely blank, is what makes a row the default rate — see the
            // model's docblock. Naming both this and `product_variant_id` is refused by
            // `withValidator()` below, not by a rule on either field — see the note there.
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],

            'product_variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->whereNull('deleted_at'),
            ],

            // The uniqueness check lives here, not on either of the two fields above:
            // `nullable` stops Laravel validating every later rule on a field once its value is
            // empty, and the commonest case this must catch — a second *default* rate, with
            // neither field sent at all — is exactly that. `cost_type` is `required`, so it is
            // validated unconditionally.
            'cost_type' => ['required', Rule::enum(ManufacturingCostType::class), $this->uniquePerCostType()],

            'rate_per_unit' => ['required', 'numeric', 'gte:0', 'max:999999999.999'],

            'is_active' => ['sometimes', 'boolean'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * `product_id` and `product_variant_id` naming both at once is ambiguous about which of the
     * three tiers the row belongs to — the database refuses it with a CHECK, and this is the
     * readable 422 in front of that. An `after()` hook rather than a rule on either field: both
     * are individually `nullable`, and a rule attached to one would never run once Laravel saw
     * that field was empty — the same trap `uniquePerCostType()` already works around by living
     * on `cost_type` instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('product_id') && $this->filled('product_variant_id')) {
                $validator->errors()->add(
                    'product_variant_id',
                    'لا يمكن تحديد منتج ومقاس منتج معاً — اختر أحدهما',
                );
            }
        });
    }

    /**
     * One row per size per cost type, one row per product per cost type, and one default per
     * cost type — the three partial indexes the migrations enforce underneath this.
     */
    protected function uniquePerCostType(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $costType = (string) $value;
            $productId = $this->intOrNull($this->input('product_id'));
            $variantId = $this->intOrNull($this->input('product_variant_id'));

            $exists = ManufacturingCostRate::query()
                ->where('cost_type', $costType)
                ->when(
                    $variantId !== null,
                    fn ($query) => $query->where('product_variant_id', $variantId),
                    fn ($query) => $productId !== null
                        ? $query->whereNull('product_variant_id')->where('product_id', $productId)
                        : $query->whereNull('product_variant_id')->whereNull('product_id'),
                )
                ->when(
                    $this->routeHasRate(),
                    fn ($query) => $query->whereKeyNot($this->route('manufacturing_cost_rate')->getKey()),
                )
                ->exists();

            if ($exists) {
                $fail(match (true) {
                    $variantId !== null => 'يوجد بالفعل معدل لهذا المقاس ولهذا النوع من التكاليف',
                    $productId !== null => 'يوجد بالفعل معدل لهذا المنتج ولهذا النوع من التكاليف',
                    default => 'يوجد بالفعل معدل افتراضي لهذا النوع من التكاليف',
                });
            }
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function routeHasRate(): bool
    {
        return $this->route('manufacturing_cost_rate') instanceof ManufacturingCostRate;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'المنتج المحدد غير موجود',
            'product_variant_id.exists' => 'المقاس المحدد غير موجود',
            'cost_type.required' => 'نوع التكلفة مطلوب',
            'cost_type.enum' => 'نوع التكلفة غير صحيح',
            'rate_per_unit.required' => 'المعدل مطلوب',
            'rate_per_unit.numeric' => 'المعدل يجب أن يكون رقماً',
            'rate_per_unit.gte' => 'المعدل يجب ألا يكون سالباً',
            'rate_per_unit.max' => 'المعدل أكبر من الحد المسموح',
            'is_active.boolean' => 'الحالة يجب أن تكون صحيحة أو خاطئة',
            'notes.max' => 'الملاحظات طويلة جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'المنتج',
            'product_variant_id' => 'المقاس',
            'cost_type' => 'نوع التكلفة',
            'rate_per_unit' => 'المعدل',
            'is_active' => 'الحالة',
            'notes' => 'الملاحظات',
        ];
    }
}
