<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Delivery;

use App\Domain\Delivery\Enums\FulfilmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100', Rule::unique('cities', 'name')->withoutTrashed()],

            // Delivered to, or collected from. Optional because all but two rows on the map are
            // `delivery`, and making the common case explicit buys nothing.
            'fulfilment_type' => ['sometimes', Rule::enum(FulfilmentType::class)],

            // Whether the customer *must* pick a neighbourhood — not whether the city has any.
            'is_region_required' => ['sometimes', 'boolean'],

            // Omit or send null for a city whose rate is not agreed yet; that is not the same as
            // 0, which means delivery really is free. Bounded by what decimal(10, 2) can hold.
            'delivery_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            'darb_branch' => ['nullable', 'string', 'max:255'],

            // Nawris's own id for this destination. Unvalidated beyond its length for the
            // reason `darb_branch` is: the vocabulary is theirs, and a format we guessed at
            // would refuse a value their API accepts.
            'nawris_government_id' => ['nullable', 'string', 'max:40'],

            // Both or neither: half a pin cannot be placed on a map, so each requires the other.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المدينة مطلوب',
            'name.min' => 'اسم المدينة قصير جداً',
            'name.max' => 'اسم المدينة طويل جداً',
            'name.unique' => 'اسم المدينة مستخدم مسبقاً',
            'fulfilment_type' => 'طريقة التسليم يجب أن تكون توصيلاً أو استلاماً من المكتب',
            'is_region_required' => 'اشتراط المنطقة يجب أن يكون صحيحاً أو خاطئاً',
            'delivery_price.numeric' => 'سعر التوصيل يجب أن يكون رقماً',
            'delivery_price.min' => 'سعر التوصيل لا يمكن أن يكون سالباً',
            'delivery_price.max' => 'سعر التوصيل أكبر من الحد المسموح',
            'darb_branch.max' => 'اسم الفرع طويل جداً',
            'nawris_government_id.max' => 'معرّف محافظة نورس طويل جداً',
            'latitude.required_with' => 'خط العرض مطلوب مع خط الطول',
            'latitude.between' => 'خط العرض يجب أن يكون بين -90 و 90',
            'longitude.required_with' => 'خط الطول مطلوب مع خط العرض',
            'longitude.between' => 'خط الطول يجب أن يكون بين -180 و 180',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المدينة',
            'fulfilment_type' => 'طريقة التسليم',
            'is_region_required' => 'اشتراط المنطقة',
            'delivery_price' => 'سعر التوصيل',
            'darb_branch' => 'فرع درب',
            'nawris_government_id' => 'محافظة نورس',
            'latitude' => 'خط العرض',
            'longitude' => 'خط الطول',
        ];
    }
}
