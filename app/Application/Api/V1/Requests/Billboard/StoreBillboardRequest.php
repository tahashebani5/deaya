<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Billboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBillboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                // Magic bytes, not the extension or the client's Content-Type header. `svg` is
                // absent and must stay absent: an SVG is an HTML document, and one served from
                // our own origin is stored XSS — the rule designs and receipts already carry.
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('media.product_images.max_kilobytes'),
            ],

            'title' => ['nullable', 'string', 'max:255'],

            // **One destination, never two.** The database carries the same rule as a check
            // constraint, because it is an invariant of the row rather than of this request —
            // but it is stated here as well so a person gets a field error instead of a 500.
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'external_url' => ['nullable', 'url', 'max:2048'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * `prohibited_if` needs a *value* to compare, and «any product at all» is not one — so the
     * mutual exclusion is expressed here instead. Stated on `external_url` rather than on
     * `product_id` because the product is the ordinary destination and the link the exception:
     * the error should land on the field somebody added second.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('product_id') && $this->filled('external_url')) {
                $validator->errors()->add(
                    'external_url',
                    'اللوحة تفتح منتجاً أو رابطاً، لا الاثنين معاً',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'صورة اللوحة مطلوبة',
            'image.mimetypes' => 'الصورة يجب أن تكون JPG أو PNG أو WEBP',
            'image.mimes' => 'الصورة يجب أن تكون JPG أو PNG أو WEBP',
            'product_id.exists' => 'المنتج المحدد غير موجود',
            'external_url.url' => 'الرابط غير صحيح',
            'ends_at.after' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => 'صورة اللوحة',
            'title' => 'عنوان اللوحة',
            'product_id' => 'المنتج',
            'external_url' => 'الرابط',
            'starts_at' => 'تاريخ البدء',
            'ends_at' => 'تاريخ الانتهاء',
        ];
    }
}
