<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ArabicText;
use PHPUnit\Framework\TestCase;

/**
 * Arabic that arrived already shaped, folded back into the letters it is made of.
 *
 * **Why the server cares about a rendering detail.** «شركة» is four letters from the Arabic
 * block; «ﺷﺮﻛﺔ» is four codepoints from the Arabic Presentation Forms block, one per shape,
 * which is what some Windows keyboards and anything pasted out of a PDF produce. The two look
 * identical everywhere they are read, so nobody notices until something has to *draw* them: the
 * invoice PDF on the phone carries a font with no glyphs for that block, and order 1228 came
 * back «تعذّر إنشاء ملف الفاتورة» over a single `ﻱ` (U+FEF1) stored in a customer's name.
 *
 * The app folds before it draws. This folds before it stores, so the letters are the letters
 * everywhere else too — search, sort, the WhatsApp message, and whatever is written next.
 *
 * Arrange - Act - Assert throughout.
 */
class ArabicTextTest extends TestCase
{
    public function test_a_shaped_yeh_is_the_same_letter_as_a_plain_one(): void
    {
        // Arrange — U+FEF1, the character that stopped order 1228's invoice.
        $shaped = "\u{FEF1}";

        // Act
        $folded = ArabicText::deshape($shaped);

        // Assert
        $this->assertSame('ي', $folded);
    }

    public function test_every_form_of_a_letter_folds_to_the_one_letter(): void
    {
        // Arrange — isolated, final, initial and medial, which is how the block is laid out.
        $forms = "\u{FE8F}\u{FE90}\u{FE91}\u{FE92}";

        // Act
        $folded = ArabicText::deshape($forms);

        // Assert
        $this->assertSame('بببب', $folded);
    }

    public function test_a_shaped_word_reads_as_the_word_it_was(): void
    {
        // Arrange — «شركة» keyed in presentation forms.
        $shaped = "\u{FEB7}\u{FEAE}\u{FEDC}\u{FE94}";

        // Act
        $folded = ArabicText::deshape($shaped);

        // Assert
        $this->assertSame('شركة', $folded);
    }

    public function test_a_lam_alef_ligature_is_the_two_letters_it_stands_for(): void
    {
        // Arrange — one codepoint (U+FEFB) that is two letters.
        $ligature = "\u{FEFB}";

        // Act
        $folded = ArabicText::deshape($ligature);

        // Assert
        $this->assertSame('لا', $folded);
    }

    public function test_the_vowel_marks_fold_and_the_tail_fragment_goes(): void
    {
        // Arrange — a shadda (U+FE7C) and the tail fragment (U+FE73), which stands for no letter.

        // Act
        $shadda = ArabicText::deshape("\u{FE7C}");
        $tail = ArabicText::deshape("\u{FE73}");

        // Assert
        $this->assertSame("\u{0651}", $shadda);
        $this->assertSame('', $tail);
    }

    public function test_text_that_was_never_shaped_is_handed_back_untouched(): void
    {
        // Arrange — the ordinary case, which must not pay for the rare one.
        $plain = 'شركة بريمولا — أكياس شحن 1,000 قطعة';

        // Act
        $folded = ArabicText::deshape($plain);

        // Assert
        $this->assertSame($plain, $folded);
    }

    public function test_it_knows_when_there_is_nothing_to_do(): void
    {
        // Arrange — what the sweeper asks before it writes a row back.

        // Act & Assert
        $this->assertFalse(ArabicText::isShaped('شركة بريمولا'));
        $this->assertTrue(ArabicText::isShaped("شركة بريمولا \u{FEF1}"));
    }

    public function test_it_leaves_everything_that_is_not_a_presentation_form_alone(): void
    {
        // Arrange — Arabic-Indic digits, a tatweel, Latin and punctuation all survive intact,
        // because this folds one block and is not a general normaliser.
        $mixed = "٠١٢ ـ AB — 12.50 \u{FEF1}";

        // Act
        $folded = ArabicText::deshape($mixed);

        // Assert
        $this->assertSame('٠١٢ ـ AB — 12.50 ي', $folded);
    }
}
