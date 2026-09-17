<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Delivery;

use App\Domain\Delivery\Models\City;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * There is deliberately no `city_id` rule: the city comes from the URL, so a body carrying
     * one is ignored rather than obeyed.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueWithinThisCity()],

            // شركة درب's zone code. Theirs, so it is stored as given and never generated here.
            'code' => ['nullable', 'string', 'max:20'],
            'darb_branch' => ['nullable', 'string', 'max:255'],
            'nawris_area_id' => ['nullable', 'string', 'max:40'],

            // Both or neither — half a pin cannot be placed on a map.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Unique inside this city only. الهضبة exists in both طرابلس and بنغازي and they are
     * different places, so the name is scoped rather than global.
     */
    private function nameUniqueWithinThisCity(): Unique
    {
        /** @var City $city */
        $city = $this->route('city');

        return Rule::unique('regions', 'name')->where('city_id', $city->getKey())->withoutTrashed();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المنطقة مطلوب',
            'name.min' => 'اسم المنطقة قصير جداً',
            'name.max' => 'اسم المنطقة طويل جداً',
            'name.unique' => 'اسم المنطقة مستخدم مسبقاً في هذه المدينة',
            'code.max' => 'رمز المنطقة طويل جداً',
            'darb_branch.max' => 'اسم الفرع طويل جداً',
            'nawris_area_id.max' => 'معرّف منطقة نورس طويل جداً',
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
            'name' => 'اسم المنطقة',
            'code' => 'رمز المنطقة',
            'darb_branch' => 'فرع درب',
            'nawris_area_id' => 'منطقة نورس',
            'latitude' => 'خط العرض',
            'longitude' => 'خط الطول',
        ];
    }
}
