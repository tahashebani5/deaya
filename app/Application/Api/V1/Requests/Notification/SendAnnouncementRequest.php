<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Notification;

use App\Domain\Notification\DTOs\AnnouncementData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «اجتماع الساعة ٤» — the message, and who it reaches.
 */
class SendAnnouncementRequest extends FormRequest
{
    /**
     * Access is declared on the route with `can:notifications.broadcast`; there is nothing extra
     * to decide here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Written out in full rather than merged from anywhere — Scramble reads this method without
     * running it, and an `array_merge` publishes the endpoint with no request body at all
     * (RULES.md §7).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Short, because it is what a phone shows on a locked screen before anything else.
            'title' => ['required', 'string', 'min:2', 'max:100'],

            // Long enough for a real message, bounded because this goes out as a push and
            // nobody reads a paragraph on a notification shade.
            'body' => ['required', 'string', 'min:2', 'max:500'],

            // Absent or null means «الجميع» — every active employee. A role narrows it.
            // `exists` without `withoutTrashed`: roles are not soft deleted.
            'role_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الإشعار مطلوب',
            'title.min' => 'عنوان الإشعار قصير جداً',
            'title.max' => 'عنوان الإشعار طويل جداً',
            'body.required' => 'نص الإشعار مطلوب',
            'body.min' => 'نص الإشعار قصير جداً',
            'body.max' => 'نص الإشعار طويل جداً',
            'role_id.exists' => 'الدور المحدد غير موجود',
        ];
    }

    /**
     * The typed shape the domain works in — no associative array crosses further than here.
     *
     * **Not named `data()`**: Laravel 13's `Illuminate\Http\Request` defines its own `data()`,
     * and overriding it with an incompatible signature is a fatal error at class-load time. Named
     * after what it returns, like `filters()` on the audit request.
     */
    public function announcement(): AnnouncementData
    {
        return AnnouncementData::fromArray($this->validated());
    }
}
