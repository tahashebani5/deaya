<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Support\Exceptions\DomainException;

/**
 * The number the courier is calling freezes when the address does, and for the same reason.
 *
 * A parcel out for delivery is being carried by somebody who already has both — they are on his
 * screen, or on the label. Changing either here would leave our copy and his disagreeing while
 * he is the only one of the two who can act on it, which is the situation
 * {@see DestinationCannotChange} exists to prevent. Rather than a second rule to keep in step,
 * this asks the same question: {@see Order::destinationIsEditable()}.
 *
 * Its own exception rather than reusing that one, because the sentence has to name the field
 * the person is looking at — «الوجهة» reads as the city to somebody correcting a phone number.
 */
final class RecipientPhoneCannotChange extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("لا يمكن تغيير هاتف الاستلام وحالة الطلبية «{$status->label()}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['recipient_phone' => [$this->getMessage()]];
    }
}
