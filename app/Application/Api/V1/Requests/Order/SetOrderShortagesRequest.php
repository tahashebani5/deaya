<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Correcting what is missing from an order.
 *
 * **Every bound is a fact about this order**, so they are built from its own lines rather than
 * declared as a constant: an id belonging to somebody else's order is refused, and a shortage
 * larger than what was ordered of that size is refused with the number it exceeded. Both are
 * things only the order can answer, and neither may be left to the client that typed them.
 *
 * The permission is on the route — unlike a status change, this endpoint costs the same grant
 * whatever it is asked to do.
 */
class SetOrderShortagesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Required rather than optional, and an empty object is a legitimate value meaning
        // «لا نقص في شيء»: the set is replaced wholesale, so «send nothing» has to be sayable.
        $rules = ['shortages' => ['required', 'array']];

        foreach ($this->lines() as $item) {
            $rules["shortages.{$item->getKey()}"] = [
                'nullable',
                'numeric',
                'min:0',
                'max:'.$item->quantity,
            ];
        }

        // Anything left over names a line this order does not have. Dropping it silently would
        // report success for a correction that went nowhere.
        $rules['shortages'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $known = $this->lines()->map(fn (OrderItem $item) => (string) $item->getKey())->all();
            $extra = array_diff(array_map('strval', array_keys((array) $value)), $known);

            if ($extra !== []) {
                $fail('هذه البنود ليست في هذه الطلبية: '.implode('، ', $extra));
            }
        };

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'shortages.required' => 'النواقص مطلوبة',
            'shortages.array' => 'النواقص تُرسَل بند بند',
        ];

        foreach ($this->lines() as $item) {
            $messages["shortages.{$item->getKey()}.max"] =
                "الناقص من «{$item->variant_label}» أكبر مما في الطلبية ({$item->quantity})";
            $messages["shortages.{$item->getKey()}.numeric"] =
                "الناقص من «{$item->variant_label}» يجب أن يكون رقماً";
        }

        return $messages;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function lines(): Collection
    {
        $order = $this->route('order');

        return $order instanceof Order ? $order->items : collect();
    }
}
