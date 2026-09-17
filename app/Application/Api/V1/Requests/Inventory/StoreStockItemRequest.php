<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreStockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fills in whatever the material already decides, before anything is validated.
     *
     * A grouped size is called what its material is called, and starts out counted in its
     * `default_unit` — so a caller naming a group need not repeat either. Merging them here rather
     * than defaulting them in the action is what lets the *uniqueness* rule below see the real
     * name: without it, a second «كيس شحن 25*35» posted under the group would pass validation with
     * an absent name and then collide with the unique index as a 500.
     */
    protected function prepareForValidation(): void
    {
        $groupId = $this->input('stock_item_group_id');

        if ($groupId === null || $groupId === '') {
            return;
        }

        $group = StockItemGroup::query()->find($groupId);

        if ($group === null) {
            return;
        }

        $this->merge([
            'name' => $this->filled('name') ? $this->input('name') : $group->name,
            'unit' => $this->filled('unit') ? $this->input('unit') : $group->default_unit->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The material this is a size of. Optional — a standalone shelf nothing else is a
            // size of is a real thing. When it *is* given, the material decides the two fields
            // below: `name` is the group's (every size of «كيس شحن» is called «كيس شحن», which is
            // what keeps (name, size) able to identify one shelf) and `unit` falls back to its
            // `default_unit`. So neither is required once a group is named.
            'stock_item_group_id' => [
                'nullable', 'integer',
                Rule::exists('stock_item_groups', 'id')->whereNull('deleted_at'),
            ],

            'name' => ['required_without:stock_item_group_id', 'nullable', 'string', 'min:2', 'max:255', $this->uniqueNameAndSize()],

            // Null for a stock item that is not a size — a roll, an ink, anything counted without
            // dimensions. The two travel together: half a size is not a size, so a width with no
            // height would produce a shelf nobody could name.
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:height_cm'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:width_cm'],

            // Required here and absent from UpdateStockItemRequest on purpose: a pile has to be
            // countable or weighable from the moment it exists, and changing it afterwards has to
            // carry every balance and cost layer snapshotted against it along — which is what
            // PATCH /stock-items/{stock_item}/unit does, under locks.
            'unit' => ['required', Rule::enum(PricingUnit::class)],

            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * One row per material *and size*: «كيس شحن» may exist at 25*35 and at 35*40 without anyone
     * inventing two names for it.
     *
     * `whereNull` rather than `where(..., null)` for an absent dimension — the latter compiles to
     * `= NULL`, which matches nothing, so two unsized items sharing a name would both pass here
     * and then collide with the index. `withoutTrashed` matches the index's own
     * `WHERE deleted_at IS NULL`: a deleted item releases its name, and the rule has to agree
     * with the constraint or one of them produces a 500 where the other produces a message.
     *
     * A private method rather than inline, so `rules()` stays statically analysable for Scramble —
     * see RULES.md §7.
     */
    protected function uniqueNameAndSize(): Unique
    {
        $width = $this->input('width_cm');
        $height = $this->input('height_cm');

        return Rule::unique('stock_items', 'name')
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
            'unit.required' => 'وحدة التخزين مطلوبة',
            'unit.enum' => 'وحدة التخزين غير صحيحة',
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
            'unit' => 'وحدة التخزين',
            'description' => 'الوصف',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
        ];
    }
}
