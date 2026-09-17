<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Middleware;

use App\Support\ArabicText;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Folds pre-shaped Arabic back into letters on every request, before anything validates or
 * stores it. {@see ArabicText} for what «pre-shaped» means and why it matters.
 *
 * **One door rather than a rule per field.** «ﺷﺮﻛﺔ» and «شركة» are the same word to every reader
 * and different bytes to every machine, so no clerk can be asked to catch it and no reviewer can
 * see it in a payload. It arrives from whichever field is typed on that keyboard, which is any
 * of them — a customer's name, an order's notes, a product, a comment. Cleaning at the entrance
 * is the only version that is still true after the next endpoint is written.
 *
 * **Beside `TrimStrings`, and for the same reason.** Laravel already normalises what a person
 * typed before the application sees it; whitespace at the ends is one such thing and this is
 * another. Registered after it in `bootstrap/app.php`.
 *
 * **Except the secrets.** A password is bytes, not words: folding one would change what the user
 * typed, and a hash written before this middleware existed would never match again.
 */
class DeshapeArabicInput extends TransformsRequest
{
    /**
     * Keys left exactly as they arrived.
     *
     * @var list<string>
     */
    protected $except = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
    ];

    /**
     * @param  mixed  $value
     */
    protected function transform($key, $value): mixed
    {
        if (in_array($key, $this->except, true) || ! is_string($value)) {
            return $value;
        }

        return ArabicText::deshape($value);
    }
}
