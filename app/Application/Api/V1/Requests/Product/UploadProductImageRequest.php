<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use App\Domain\Catalog\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UploadProductImageRequest extends FormRequest
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
            // `image` on top of the mime list: it verifies the file really is an image rather
            // than trusting a renamed extension or a client-supplied content type.
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('media.product_images.max_kilobytes'),
                $this->withinTheProductsAllowance(),
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Refuses a sixth photograph on a product that already carries five.
     *
     * **A rule rather than a check inside the action**, because the file has already been
     * received by the time an action runs: refusing here is the last point where the answer
     * costs nothing. The app refuses earlier still — before the picker even opens — and this
     * is the boundary behind that courtesy.
     *
     * Soft-deleted images do not count. The relation excludes them, and that is correct rather
     * than incidental: deletion removes the file from the disk for real, so the slot it held is
     * genuinely free.
     */
    private function withinTheProductsAllowance(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $product = $this->route('product');

            // Never null in practice — the route binds it or 404s before validation. Guarded
            // anyway, because a rule that assumes its route is a rule that fatals when the
            // route changes shape.
            if (! $product instanceof Product) {
                return;
            }

            $cap = (int) config('media.product_images.max_per_product');

            if ($product->images()->count() >= $cap) {
                $fail("لا يمكن تجاوز {$cap} صور للمنتج الواحد — احذف صورة قديمة لإضافة جديدة");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'الصورة مطلوبة',
            'image.image' => 'الملف المرفوع ليس صورة صالحة',
            'image.mimes' => 'الصيغ المسموحة: jpeg، jpg، png، webp',
            'image.max' => 'حجم الصورة أكبر من الحد المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => 'الصورة',
            'alt_text' => 'النص البديل',
            'is_primary' => 'الصورة الرئيسية',
        ];
    }
}
