<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\UpdateStockItemGroup;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating, and `default_unit` **is** editable here — unlike a stock item's own
 * `unit`, which no `PUT` may touch.
 *
 * The two look alike and are not. An item's unit is snapshotted onto every balance and cost layer
 * that ever touched it, so moving it means restamping all of them under locks. A material's
 * default decides what a size created *later* starts out counted in, and touches nothing that
 * already exists.
 *
 * Renaming, by contrast, is the edit with reach: every size of the material is renamed with it in
 * the same transaction, because a grouped item carries its group's name. See
 * {@see UpdateStockItemGroup}.
 */
class UpdateStockItemGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255', $this->uniqueName()],
            'default_unit' => ['sometimes', Rule::enum(PricingUnit::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The same uniqueness {@see StoreStockItemGroupRequest} enforces, with this row ignored so a
     * save that does not rename does not collide with itself.
     *
     * A private method rather than inline, so `rules()` stays statically analysable for Scramble —
     * see RULES.md §7.
     */
    protected function uniqueName(): Unique
    {
        /** @var StockItemGroup $group */
        $group = $this->route('stock_item_group');

        return Rule::unique('stock_item_groups', 'name')
            ->ignore($group->getKey())
            ->withoutTrashed();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم التصنيف مطلوب',
            'name.min' => 'اسم التصنيف قصير جداً',
            'name.max' => 'اسم التصنيف طويل جداً',
            'name.unique' => 'يوجد تصنيف بنفس الاسم',
            'default_unit.enum' => 'وحدة التخزين الافتراضية غير صحيحة',
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
            'name' => 'اسم التصنيف',
            'default_unit' => 'وحدة التخزين الافتراضية',
            'description' => 'الوصف',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
        ];
    }
}
