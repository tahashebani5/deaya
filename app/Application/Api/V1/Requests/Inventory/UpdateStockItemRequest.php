<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Inventory\Models\StockItem;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating, minus `unit`.
 *
 * **`unit` is deliberately not a rule here**, so a PUT can never change it. Every `WarehouseStock`
 * and `StockBatch` for this shelf carries a snapshot of it, and rewriting the item's copy while
 * those keep the old one is precisely the drift `ApplyStockChange::guardUnit()` exists to catch.
 * A unit change is `PATCH /stock-items/{stock_item}/unit`, which moves all of them together.
 *
 * The dimensions *are* editable, and that is not the same risk: they name the shelf, they are
 * snapshotted nowhere, and correcting a typo in a size must not require deleting a pile.
 */
class UpdateStockItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255', $this->uniqueNameAndSize()],
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:height_cm'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:width_cm'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The same (name, size) rule {@see StoreStockItemRequest::uniqueNameAndSize()} carries — see
     * there for why `whereNull` and `withoutTrashed` — with this row ignored, so re-saving an
     * item without renaming it does not collide with itself.
     */
    protected function uniqueNameAndSize(): Unique
    {
        $width = $this->input('width_cm');
        $height = $this->input('height_cm');

        /** @var StockItem $item */
        $item = $this->route('stock_item');

        return Rule::unique('stock_items', 'name')
            ->ignore($item->getKey())
            ->where(fn (Builder $query) => $query
                ->when($width === null || $width === '', fn (Builder $q) => $q->whereNull('width_cm'))
                ->when($width !== null && $width !== '', fn (Builder $q) => $q->where('width_cm', (int) $width))
                ->when($height === null || $height === '', fn (Builder $q) => $q->whereNull('height_cm'))
                ->when($height !== null && $height !== '', fn (Builder $q) => $q->where('height_cm', (int) $height)))
            ->withoutTrashed();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المادة مطلوب',
            'name.min' => 'اسم المادة قصير جداً',
            'name.max' => 'اسم المادة طويل جداً',
            'name.unique' => 'توجد مادة بنفس الاسم والمقاس',
            'width_cm.required_with' => 'العرض والطول يجب أن يُدخلا معاً',
            'height_cm.required_with' => 'العرض والطول يجب أن يُدخلا معاً',
            'width_cm.min' => 'العرض يجب أن يكون أكبر من صفر',
            'height_cm.min' => 'الطول يجب أن يكون أكبر من صفر',
            'description.max' => 'الوصف طويل جداً',
            'sort_order.integer' => 'الترتيب يجب أن يكون رقماً صحيحاً',
            'sort_order.min' => 'الترتيب لا يمكن أن يكون سالباً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المادة',
            'width_cm' => 'العرض',
            'height_cm' => 'الطول',
            'description' => 'الوصف',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
        ];
    }
}
