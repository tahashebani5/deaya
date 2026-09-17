<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\PurchaseOrder;

use App\Domain\PurchaseOrder\Actions\ReceivePurchaseOrder;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a purchase order by hand.
 *
 * Only `arrived` and `cancelled` are legal targets here — `new` is where every order starts and
 * `completed` is never a manual choice: {@see ReceivePurchaseOrder}
 * is the only path there, once the stock itself says every line is fully received. Whether the
 * *move* is legal from the order's current status (e.g. cancelling something already completed)
 * is a domain question, answered by {@see PurchaseOrderStatus::canMoveTo()} — this only checks
 * that the word means something.
 */
class ChangePurchaseOrderStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                PurchaseOrderStatus::Arrived->value,
                PurchaseOrderStatus::Cancelled->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'الحالة المطلوبة مطلوبة',
            'status.in' => 'لا يمكن تحويل أمر الشراء إلى هذه الحالة يدوياً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'الحالة'];
    }

    public function status(): PurchaseOrderStatus
    {
        return PurchaseOrderStatus::from((string) $this->validated('status'));
    }
}
