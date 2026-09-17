<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\DTOs\TransitionField;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Support\TransitionFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving an order.
 *
 * **The permission is checked here rather than on the route, and that is deliberate.** Every
 * other endpoint declares its guard with `can:` beside the route, which is the rule this
 * codebase follows — but the permission a status change costs depends on the status being asked
 * for, and a route cannot see the request body. `authorize()` is the one place Laravel offers
 * that both sees the body and answers 403 without a controller writing a response.
 *
 * The two dispatch statuses resolve to whichever the destination city implies, so the
 * permission is looked up against the *resolved* target — otherwise a clerk allowed to dispatch
 * could be refused for naming the other one of the pair, which they did not choose anyway.
 */
class ChangeOrderStatusRequest extends FormRequest
{
    /** The one described field that lives at the root of the payload rather than inside `fields`. */
    private const LIFTED_REASON = 'reason';

    /**
     * Resolved once: `rules()`, `messages()` and `attributes()` all ask for the same list, and
     * building it reads the order's designs.
     *
     * Named for what it holds rather than `$fields`, which on a request already means "whatever
     * the caller posted".
     *
     * @var list<TransitionField>|null
     */
    private ?array $descriptors = null;

    public function authorize(): bool
    {
        $target = OrderStatus::tryFrom((string) $this->input('status'));

        // An unknown status is a validation failure, not a permission one: let rules() produce
        // the readable 422 rather than answering 403 for a word that means nothing.
        if ($target === null) {
            return true;
        }

        return $this->user()?->can($target->permission()->value) ?? false;
    }

    /**
     * The reason travels either way in.
     *
     * The app sends every input the same way — inside `fields`, as the transition described it —
     * while `reason` at the root is how this endpoint has always been called. Lifting one into
     * the other here means the domain, the rules below and every existing caller keep seeing
     * exactly one reason.
     */
    protected function prepareForValidation(): void
    {
        $fields = $this->input('fields');

        if (! is_array($fields) || ! array_key_exists(self::LIFTED_REASON, $fields)) {
            return;
        }

        $reason = $fields[self::LIFTED_REASON];
        unset($fields[self::LIFTED_REASON]);

        $this->merge([
            'reason' => $this->input('reason') ?? $reason,
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'status' => ['required', Rule::enum(OrderStatus::class)],

            // Required only for a cancellation. Enforced in the domain too, so the rule holds
            // for every caller — this copy exists to give the friendlier field-level 422.
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,'.OrderStatus::Cancelled->value],

            // Nothing the move did not offer. Dropping an unknown key silently would teach a
            // client that it had been recorded.
            'fields' => ['sometimes', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                $offered = array_map(fn (TransitionField $field) => $field->key, $this->transitionFields());
                $extra = array_diff(array_keys((array) $value), $offered);

                if ($extra !== []) {
                    $fail('هذه الحقول غير مطلوبة في هذا الانتقال: '.implode('، ', $extra));
                }
            }],
        ];

        // The same list the resource described to the app, turned into what this endpoint will
        // accept. One source, so a form cannot offer a field the request refuses.
        //
        // The reason is the one exception, and only because it has been at the root of this
        // payload since before `fields` existed: `prepareForValidation()` moved it there, so a
        // rule for it here would demand a key that is deliberately no longer present.
        foreach ($this->transitionFields() as $field) {
            if ($field->key === self::LIFTED_REASON) {
                continue;
            }

            $rules += $field->rules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'status.required' => 'الحالة المطلوبة مطلوبة',
            'reason.required_if' => 'إلغاء الطلبية يتطلب ذكر السبب',
        ];

        foreach ($this->transitionFields() as $field) {
            $messages += $field->messages();
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'status' => 'الحالة',
            'reason' => 'السبب',
        ];

        foreach ($this->transitionFields() as $field) {
            $attributes["fields.{$field->key}"] = $field->label;
        }

        return $attributes;
    }

    /**
     * What this particular move asks this particular order for.
     *
     * Empty when the payload names no status this build knows: `rules()` is what produces the
     * readable 422 for that, and a field list for a status that does not exist would only add
     * noise on top of it.
     *
     * @return list<TransitionField>
     */
    private function transitionFields(): array
    {
        $order = $this->route('order');
        $target = OrderStatus::tryFrom((string) $this->input('status'));

        if (! $order instanceof Order || $target === null) {
            return [];
        }

        // The same user the resource described the form to, so what the app was offered and
        // what this endpoint accepts stay one list — a money box withheld from a driver on the
        // screen is a money box this request has never heard of either.
        return $this->descriptors ??= TransitionFields::for($order, $target, $this->user());
    }
}
