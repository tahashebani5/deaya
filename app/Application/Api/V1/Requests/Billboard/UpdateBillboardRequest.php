<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Billboard;

/**
 * Editing a banner.
 *
 * Extends the create request and makes the picture optional — everything else is already
 * `nullable`, and on this endpoint null means «left alone» rather than «cleared», which is what
 * `UpdateBillboard` implements. Switching a banner off must not wipe the schedule somebody set
 * last week.
 */
class UpdateBillboardRequest extends StoreBillboardRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['image'][0] = 'sometimes';

        return $rules;
    }
}
