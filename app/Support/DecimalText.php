<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A decimal on its way into a sentence, without the zeros its column padded it with.
 *
 * **The scale is a storage decision, not a thing to read.** Quantities are `decimal:3` because a
 * shelf weighed in kilograms needs three places, and money is `decimal:2` because money is; a
 * count of a thousand bags is stored at the same scale as the weight beside it and comes back as
 * «1000.000». On a screen that is three zeros nobody typed, sitting in the box the delivery
 * screen opens holding and in the hint underneath it.
 *
 * **Only the padding goes.** Half a kilogram is «12.5» — the fraction that was measured is the
 * fraction the sentence means, exactly as the app's own `trimDecimals` has it. This is that rule
 * on the server side, for the sentences the server writes.
 *
 * **String surgery, never a cast.** `(float) '105250.000'` is a value that prints itself back in
 * ways nobody asked for, and these figures reach the screen unrounded on purpose.
 */
final class DecimalText
{
    /**
     * «1000.000» → «1000», «12.500» → «12.5», «300» → «300».
     *
     * The guard is the whole reason this lives in one place: `rtrim('300', '0')` is «3», and the
     * two hand-rolled copies this replaces each had to remember not to be handed an integer.
     */
    public static function trim(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
