<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The order the headings should now appear in — the whole list, in one call.
 *
 * `distinct` matters more than it looks: the same id twice would leave the second write deciding
 * the row's position, and a list that came back in an order nobody dragged reads as the drag
 * having failed.
 */
class ReorderProductCategoriesRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('product_categories', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'أرسل ترتيب التصنيفات',
            'ids.*.distinct' => 'تكرّر تصنيف في الترتيب المُرسَل',
            'ids.*.exists' => 'أحد التصنيفات في الترتيب غير موجود',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['ids' => 'ترتيب التصنيفات'];
    }
}
