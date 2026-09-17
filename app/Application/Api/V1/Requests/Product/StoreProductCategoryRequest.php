<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Product;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Translates the boolean this field used to be into the vocabulary it is now.
     *
     * **The shipped app still writes `skips_production`**, and it will keep writing it until an
     * app release replaces the switch on its sheet with a picker — see OUTSOURCED-PRODUCTS.md §8.
     * Translating at the boundary is what lets the domain speak one dialect: `ProductCategoryData`
     * and everything under it know only `production_mode`.
     *
     * Three rules, in order:
     *
     * 1. `production_mode` sent → it wins, whatever else came with it.
     * 2. Only the boolean sent → `true` is «سادة». `false` is «مطبوعة» — **unless the heading is
     *    already «وسيط»**, in which case it is left alone. That exception is the whole point: an
     *    old build has no way to *say* «وسيط», so its `false` means «لم يُسأل عني», not «حوّلني
     *    إلى مطبوعة», and reading it the other way would silently demote a heading somebody had
     *    deliberately set — losing the cost prices under it from every order taken afterwards.
     * 3. Neither sent, on an update → the stored mode, so a partial write changes nothing it did
     *    not mention. On a create there is nothing stored, and the default is «مطبوعة».
     */
    protected function prepareForValidation(): void
    {
        $this->prepareProductionMode();

        // Every field whose absence would otherwise answer for the row. `production_mode` is
        // handled above instead, because it also has a legacy key to translate.
        $this->keepStored('parent_id');
        $this->keepStored('is_investable');
        $this->keepStored('is_active');
        $this->keepStored('sort_order');
    }

    /** @see prepareForValidation() — the three rules are stated there. */
    private function prepareProductionMode(): void
    {
        if ($this->has('production_mode')) {
            return;
        }

        $category = $this->route('product_category');
        $stored = $category instanceof ProductCategory ? $category->production_mode : null;

        if ($this->has('skips_production')) {
            $this->merge([
                'production_mode' => match (true) {
                    $this->boolean('skips_production') => ProductionMode::None->value,
                    $stored === ProductionMode::Outsourced => ProductionMode::Outsourced->value,
                    default => ProductionMode::InHouse->value,
                },
            ]);

            return;
        }

        if ($stored !== null) {
            $this->merge(['production_mode' => $stored->value]);
        }
    }

    /**
     * **On an update, a field this request does not mention keeps what is stored.**
     *
     * Not what a PUT usually means, and deliberately so. A PUT replaces the whole
     * representation, which makes an omitted key an answer — right for a client that sends the
     * whole representation, and wrong for the build already in people's hands, whose rename
     * carries a name and a mode and nothing else.
     *
     * What each omission used to say on that build's behalf, unasked:
     *
     * - `parent_id` → «اجعله رئيسياً». Every subheading it renamed came back a root. Quiet while
     *   nothing depended on the link, and not quiet since: a rooted subheading holding
     *   `is_investable = null` stops inheriting its family's yes and answers «لا», closing every
     *   shelf under it to new deals.
     * - `is_investable` → «اسأل الأب», which on a root is «لا» — a funded heading closed by a
     *   rename, with nothing on any screen to say why.
     * - `is_active` → «أعِده إلى القوائم»: a heading somebody stopped, back in every picker.
     * - `sort_order` → «صفر»: the heading jumps to the head of the catalogue.
     *
     * **Saying any of them still works; it just has to be said.** On a create there is nothing
     * stored, nothing is merged, and the defaults in {@see ProductCategoryData} stand.
     */
    private function keepStored(string $field): void
    {
        if ($this->has($field)) {
            return;
        }

        $category = $this->route('product_category');

        if ($category instanceof ProductCategory) {
            $this->merge([$field => $category->getAttribute($field)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `withoutTrashed` matches the partial unique index in the database: a category that
            // was deleted releases its name, and the rule has to agree with the constraint or
            // one of them produces a 500 where the other produces a message.
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('product_categories', 'name')->withoutTrashed(),
            ],

            // The heading this one sits under. Null — or absent — makes it a heading in its
            // own right.
            //
            // **`whereNull('parent_id')` is the one-level rule**, stated where it can be
            // explained: a category may only be filed under a *root*, so «أكياس ورقية» cannot
            // itself acquire children. Nothing in the catalogue is three deep, and a tree of
            // arbitrary depth costs every screen a recursive render for a shape nobody asked for.
            //
            // On an update, absent means «اتركه حيث هو» rather than «اجعله رئيسياً» — see
            // `keepStored()` for the rename that used to root a subheading in silence.
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('product_categories', 'id')
                    ->whereNull('deleted_at')
                    ->whereNull('parent_id'),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Optional because a category is offered the moment it is created; hiding one is a
            // later decision, taken from the list. An update that omits it keeps the stored
            // answer — see `keepStored()`.
            'is_active' => ['sometimes', 'boolean'],

            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            // How goods under this heading come to exist, and therefore which road an order made
            // only of them walks — see `ProductCategory::productionMode()`. Optional and
            // `in_house` by default: a heading nobody has thought about sends its orders down the
            // road every order took before this field existed.
            'production_mode' => ['sometimes', Rule::enum(ProductionMode::class)],

            // Whether a deal may be opened against the shelves under this heading — see
            // `ProductCategory::isInvestable()`. **Three-valued**: true, false, and null for
            // «اسأل الأب», which is what lets a subheading be excluded from an investable family
            // rather than merely un-asked-about. An update that omits it keeps what is stored —
            // see `keepStored()`.
            'is_investable' => ['sometimes', 'nullable', 'boolean'],

            // **The boolean this replaced, still accepted for the shipped app.** Never read past
            // this class — `prepareForValidation()` turns it into `production_mode` before any
            // rule runs, and the domain is never shown it.
            'skips_production' => ['sometimes', 'boolean'],
        ];
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
            'name.unique' => 'التصنيف مسجّل مسبقاً',
            'parent_id.exists' => 'التصنيف الرئيسي غير موجود، أو هو نفسه تصنيف فرعي',
            'description.max' => 'وصف التصنيف طويل جداً',
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
            'parent_id' => 'التصنيف الرئيسي',
            'description' => 'وصف التصنيف',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
            'production_mode' => 'طريقة التنفيذ',
            'is_investable' => 'قابل للاستثمار',
            'skips_production' => 'يتخطّى التصميم والطباعة',
        ];
    }
}
