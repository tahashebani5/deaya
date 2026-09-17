<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which product sizes draw on one material — the whole set, every time.
 *
 * `present` rather than `required`: an empty array is a real answer here, and `required` would
 * refuse the one payload that says «nothing draws on this any more».
 */
class SetStockItemVariantsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_ids' => ['present', 'array'],
            'variant_ids.*' => [
                'integer',
                'distinct',
                // A soft-deleted size still occupies its id; linking one would file a pile behind
                // a row nothing can order.
                Rule::exists(ProductVariant::class, 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variant_ids.present' => 'قائمة المقاسات مطلوبة',
            'variant_ids.array' => 'قائمة المقاسات غير صحيحة',
            'variant_ids.*.integer' => 'المقاس المحدد غير صحيح',
            'variant_ids.*.exists' => 'المقاس المحدد غير موجود',
            'variant_ids.*.distinct' => 'لا يمكن تكرار نفس المقاس أكثر من مرة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'variant_ids' => 'المقاسات',
            'variant_ids.*' => 'المقاس',
        ];
    }
}
