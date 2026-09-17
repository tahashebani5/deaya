<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use App\Domain\Shortage\Actions\UpdateShortage;

/**
 * Correcting a hand-written shortage.
 *
 * The same fields as creating one, minus the assignee — that has its own endpoint and its own
 * grant, because routing work is a different job from describing it.
 *
 * Whether this shortage may be edited at all is a domain question and is answered there: an
 * order-born one is refused by {@see UpdateShortage}, not by a rule here, because the reason is
 * about where its numbers come from rather than about the shape of the payload.
 */
class UpdateShortageRequest extends StoreShortageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['assigned_to_user_id']);

        return $rules;
    }
}
