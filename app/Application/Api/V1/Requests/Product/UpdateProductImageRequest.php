<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits an image's metadata. The file itself is never replaced — upload a new one and delete
 * the old, so a cached URL can never start pointing at different content.
 */
class UpdateProductImageRequest extends FormRequest
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
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            // Only promoting is meaningful: a product needs one primary image, so demoting the
            // current one without naming a replacement is refused.
            'is_primary' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_primary.accepted' => 'لتغيير الصورة الرئيسية اختر صورة أخرى لتكون الرئيسية',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'alt_text' => 'النص البديل',
            'sort_order' => 'الترتيب',
            'is_primary' => 'الصورة الرئيسية',
        ];
    }
}
