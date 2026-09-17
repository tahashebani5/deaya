<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Customer;

use App\Domain\Delivery\Models\Region;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Digits only, wide enough for both Libyan mobiles (0912345678) and
            // landlines (0213334444). One number identifies exactly one customer, so it is
            // unique here *and* in the database — validation gives a readable 422, the unique
            // index is what actually holds under concurrent requests.
            'phone' => ['required', 'string', 'regex:/^\d{9,15}$/', Rule::unique('customers', 'phone')->withoutTrashed()],
            'is_active' => ['sometimes', 'boolean'],

            // Shops are created together with the customer; the code never comes from here.
            'shops' => ['sometimes', 'array'],
            'shops.*.name' => ['required', 'string', 'max:255'],
            // الموقع: a place on the delivery map, which is the same map an order is addressed
            // from. `exists` keeps a stale id from a client's cached list out of the database.
            'shops.*.city_id' => ['required', 'integer', Rule::exists('cities', 'id')->withoutTrashed()],
            // Optional in both directions — most cities have no neighbourhoods, and the clerk
            // taking a customer over the phone often does not know the district yet.
            // `is_region_required` is a delivery rule and it is the order that answers it.
            // Belonging to the chosen city is checked in `withValidator()`.
            'shops.*.region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')->withoutTrashed()],
            // الإحداثيات, no longer asked for by any screen — see the migration. Still accepted
            // so the pin can come back without an API change, and still bounded to the real
            // ranges so a swapped pair is rejected rather than silently placing the shop wrongly.
            // Omitting them leaves whatever the shop already had; see SyncCustomerShops.
            'shops.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'shops.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'shops.*.page_url' => ['nullable', 'url', 'max:2048'],
            // مجال العمل. Optional — most shops on record have none — and `exists` keeps a
            // stale id from a client's cached list out of the database. Deactivated fields are
            // still accepted: a shop already recorded under one must survive being saved again.
            'shops.*.business_field_id' => ['nullable', 'integer', Rule::exists('business_fields', 'id')->withoutTrashed()],
        ];
    }

    /**
     * The neighbourhood has to be inside the city that was picked.
     *
     * `exists` alone is happy with «طرابلس / سوق الخميس الزاوية»: the region is real, it is
     * simply not in that city. The pair is what has to be valid, and a pair is more than either
     * rule can see on its own — so it is checked here, where both values are in hand.
     *
     * One query per shop that named a region. A customer has a handful of shops, and the
     * alternative — one query plus grouping in PHP — is more code for a saving nobody measures.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shops = $this->input('shops');

            if (! is_array($shops)) {
                return;
            }

            foreach ($shops as $index => $shop) {
                $cityId = is_array($shop) ? ($shop['city_id'] ?? null) : null;
                $regionId = is_array($shop) ? ($shop['region_id'] ?? null) : null;

                // Nothing to compare: either half missing is already the other rules' business.
                if ($cityId === null || $regionId === null || $regionId === '') {
                    continue;
                }

                $isInThatCity = Region::query()
                    ->whereKey($regionId)
                    ->where('city_id', $cityId)
                    ->exists();

                if (! $isInThatCity) {
                    $validator->errors()->add(
                        "shops.{$index}.region_id",
                        'المنطقة المختارة ليست ضمن المدينة المحددة',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم العميل مطلوب',
            'name.min' => 'اسم العميل يجب أن يكون حرفين على الأقل',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.regex' => 'رقم الهاتف يجب أن يكون أرقاماً فقط (من 9 إلى 15 رقماً)',
            'phone.unique' => 'رقم الهاتف مستخدم مسبقاً لعميل آخر',
            'is_active.boolean' => 'حالة التنشيط يجب أن تكون صحيحة أو خاطئة',
            'shops.array' => 'المحلات يجب أن تكون قائمة',
            'shops.*.name.required' => 'اسم المكان مطلوب',
            'shops.*.city_id.required' => 'مدينة المحل مطلوبة',
            'shops.*.city_id.exists' => 'المدينة المختارة غير موجودة',
            'shops.*.region_id.exists' => 'المنطقة المختارة غير موجودة',
            'shops.*.latitude.numeric' => 'خط العرض يجب أن يكون رقماً',
            'shops.*.latitude.between' => 'خط العرض يجب أن يكون بين -90 و 90',
            'shops.*.longitude.numeric' => 'خط الطول يجب أن يكون رقماً',
            'shops.*.longitude.between' => 'خط الطول يجب أن يكون بين -180 و 180',
            'shops.*.page_url.url' => 'رابط الصفحة غير صحيح',
            'shops.*.business_field_id.exists' => 'مجال العمل المختار غير موجود',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم العميل',
            'phone' => 'رقم الهاتف',
            'is_active' => 'الحالة',
            'shops' => 'المحلات',
            'shops.*.name' => 'اسم المكان',
            'shops.*.city_id' => 'المدينة',
            'shops.*.region_id' => 'المنطقة',
            'shops.*.latitude' => 'خط العرض',
            'shops.*.longitude' => 'خط الطول',
            'shops.*.page_url' => 'رابط الصفحة',
            'shops.*.business_field_id' => 'مجال العمل',
        ];
    }
}
