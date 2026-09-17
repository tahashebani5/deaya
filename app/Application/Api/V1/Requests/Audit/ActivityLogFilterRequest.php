<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Audit;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Audit\Queries\ActivityFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string every history endpoint accepts.
 *
 * A FormRequest for a GET, unlike the other index endpoints in this API, and deliberately: a
 * date that does not parse or an event nobody can produce should come back as a readable 422
 * rather than as an empty page the caller reads as "nothing happened". Being a FormRequest is
 * also what publishes these as documented query parameters in the OpenAPI spec.
 */
class ActivityLogFilterRequest extends FormRequest
{
    /**
     * Access is declared on the routes with `can:logs.view`; there is nothing extra to decide
     * here.
     */
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
            // Only one of created · updated · deleted · restored.
            'event' => ['sometimes', 'nullable', Rule::in(AuditEvent::values())],

            // Only what this user did. Not `exists:users,id` — a deleted user's entries are
            // exactly what someone auditing them is looking for.
            'causer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // Only this kind of record. Ignored by the per-record endpoints, which already know.
            'subject_type' => ['sometimes', 'nullable', Rule::in(AuditSubject::values())],

            // Inclusive on both ends: `to` counts the whole of its day.
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],

            // Not validated, clamped — see perPage(). Declared so it appears in the spec.
            'per_page' => ['sometimes', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event.in' => 'نوع الحدث غير صحيح',
            'subject_type.in' => 'نوع السجل غير صحيح',
            'causer_id.integer' => 'معرف المستخدم غير صحيح',
            'from.date' => 'تاريخ البداية غير صحيح',
            'to.date' => 'تاريخ النهاية غير صحيح',
            'to.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية',
        ];
    }

    /**
     * The typed shape the domain works in — no associative array crosses further than here.
     */
    public function filters(): ActivityFilters
    {
        return ActivityFilters::fromArray($this->validated());
    }

    /**
     * Clamped, not rejected — the same treatment `per_page` gets on every other list in this
     * API. A client asking for a thousand entries gets a hundred, not a 422.
     */
    public function perPage(): int
    {
        return min(max((int) $this->integer('per_page', 15), 1), 100);
    }
}
