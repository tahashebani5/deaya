<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The picture the catalogue prints above a heading.
 *
 * The same limits product photos are held to — one set of numbers for the business's own
 * marketing images, because a second would be a third place that has to agree. `image` on top of
 * the mime list verifies the file really is one rather than trusting a renamed extension.
 */
class SetProductCategoryImageRequest extends FormRequest
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
            'image' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('media.product_images.mimes')),
                'max:'.config('media.product_images.max_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'اختر صورة التصنيف',
            'image.image' => 'الملف المرسل ليس صورة',
            'image.max' => 'حجم الصورة أكبر من المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['image' => 'صورة التصنيف'];
    }
}
