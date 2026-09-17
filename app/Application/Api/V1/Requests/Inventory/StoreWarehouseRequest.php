<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Inventory\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
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
            // `withoutTrashed`, like every unique rule here: the index is partial, so a deleted
            // warehouse releases its name and the 422 must agree with what the index allows.
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('warehouses', 'name')->withoutTrashed(),
            ],

            // What kind of place this is — the main store or the workshop floor.
            'type' => ['required', Rule::enum(WarehouseType::class)],

            // Where it physically is, written the way staff say it. Optional: a site that
            // exists before anyone wrote its address down is a real state.
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المخزن مطلوب',
            'name.min' => 'اسم المخزن قصير جداً',
            'name.max' => 'اسم المخزن طويل جداً',
            'name.unique' => 'اسم المخزن مستخدم مسبقاً',
            'type.required' => 'نوع المخزن مطلوب',
            'type.enum' => 'نوع المخزن غير صحيح',
            'location.max' => 'الموقع طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المخزن',
            'type' => 'نوع المخزن',
            'location' => 'الموقع',
        ];
    }
}
