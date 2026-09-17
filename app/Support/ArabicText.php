<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Arabic that arrived already shaped, folded back into the letters it is made of.
 *
 * **Two ways of writing the same word.** «شركة» is four letters from the Arabic block
 * (U+0621–U+064A), and whatever draws them decides how each joins to its neighbour. Text keyed
 * on some Windows layouts, pasted out of a PDF, or copied from an older system arrives
 * pre-joined instead — «ﺷﺮﻛﺔ», four codepoints from the **Arabic Presentation Forms** block
 * (U+FE70–U+FEFC), one per shape. On every screen the two are indistinguishable, because the
 * system fonts carry both.
 *
 * **Until something has to draw them into a file.** Almarai — like most modern Arabic faces —
 * has no glyphs for that block, because the shaping is the renderer's job, and the PDF font
 * subsetter throws rather than skipping what it cannot find. Order 1228's invoice failed on a
 * single `ﻱ` (U+FEF1) sitting in stored text.
 *
 * The app folds before it draws; this folds before it stores, so the same word is the same bytes
 * for everything else too — a `LIKE` search, an `ORDER BY`, a duplicate check, an export.
 *
 * **Not a general normaliser, and deliberately not `Normalizer::normalize()`.** `intl` is not
 * guaranteed on the hosting this deploys to, and NFKC rewrites a great deal more than this one
 * block. Arabic-Indic digits, tatweel, punctuation and Latin all pass through untouched.
 */
final class ArabicText
{
    private const FIRST = 0xFE70;

    private const LAST = 0xFEFC;

    /**
     * Every codepoint in the block against the letters it was drawn from.
     *
     * **Ranges rather than 140 entries**, because the block is laid out by letter: two forms for
     * a letter that never joins to its left (ا، د، ر، و), four for one that does — isolated,
     * final, initial, medial, in that order. The last four are the lam-alef ligatures, one
     * codepoint standing for two letters, which is why this maps to strings and not to letters.
     *
     * The twin of `_folds` in `frontend/lib/core/utils/arabic_text.dart`. The two are kept
     * identical on purpose: a name folded one way on the server and another on the phone would
     * be two names.
     *
     * @var list<array{int, int, string}>
     */
    private const FOLDS = [
        [0xFE70, 0xFE71, "\u{064B}"], // ً — the tatweel the second form carries is a stretch, not a mark
        [0xFE72, 0xFE72, "\u{064C}"], // ٌ
        [0xFE73, 0xFE73, ''],         // the tail fragment: a piece of a shape, standing for no letter
        [0xFE74, 0xFE74, "\u{064D}"], // ٍ
        [0xFE76, 0xFE77, "\u{064E}"], // َ
        [0xFE78, 0xFE79, "\u{064F}"], // ُ
        [0xFE7A, 0xFE7B, "\u{0650}"], // ِ
        [0xFE7C, 0xFE7D, "\u{0651}"], // ّ
        [0xFE7E, 0xFE7F, "\u{0652}"], // ْ
        [0xFE80, 0xFE80, 'ء'],
        [0xFE81, 0xFE82, 'آ'],
        [0xFE83, 0xFE84, 'أ'],
        [0xFE85, 0xFE86, 'ؤ'],
        [0xFE87, 0xFE88, 'إ'],
        [0xFE89, 0xFE8C, 'ئ'],
        [0xFE8D, 0xFE8E, 'ا'],
        [0xFE8F, 0xFE92, 'ب'],
        [0xFE93, 0xFE94, 'ة'],
        [0xFE95, 0xFE98, 'ت'],
        [0xFE99, 0xFE9C, 'ث'],
        [0xFE9D, 0xFEA0, 'ج'],
        [0xFEA1, 0xFEA4, 'ح'],
        [0xFEA5, 0xFEA8, 'خ'],
        [0xFEA9, 0xFEAA, 'د'],
        [0xFEAB, 0xFEAC, 'ذ'],
        [0xFEAD, 0xFEAE, 'ر'],
        [0xFEAF, 0xFEB0, 'ز'],
        [0xFEB1, 0xFEB4, 'س'],
        [0xFEB5, 0xFEB8, 'ش'],
        [0xFEB9, 0xFEBC, 'ص'],
        [0xFEBD, 0xFEC0, 'ض'],
        [0xFEC1, 0xFEC4, 'ط'],
        [0xFEC5, 0xFEC8, 'ظ'],
        [0xFEC9, 0xFECC, 'ع'],
        [0xFECD, 0xFED0, 'غ'],
        [0xFED1, 0xFED4, 'ف'],
        [0xFED5, 0xFED8, 'ق'],
        [0xFED9, 0xFEDC, 'ك'],
        [0xFEDD, 0xFEE0, 'ل'],
        [0xFEE1, 0xFEE4, 'م'],
        [0xFEE5, 0xFEE8, 'ن'],
        [0xFEE9, 0xFEEC, 'ه'],
        [0xFEED, 0xFEEE, 'و'],
        [0xFEEF, 0xFEF0, 'ى'],
        [0xFEF1, 0xFEF4, 'ي'], // the one that took order 1228's invoice down
        [0xFEF5, 0xFEF6, 'لآ'],
        [0xFEF7, 0xFEF8, 'لأ'],
        [0xFEF9, 0xFEFA, 'لإ'],
        [0xFEFB, 0xFEFC, 'لا'],
    ];

    /** @var array<string, string>|null */
    private static ?array $table = null;

    /**
     * The same text with every pre-shaped letter written as the letter itself.
     *
     * Returns the string it was given when there is nothing to fold, which is every ordinary
     * Arabic string this application handles. The rare case pays; the common one does not.
     */
    public static function deshape(string $text): string
    {
        if (! self::isShaped($text)) {
            return $text;
        }

        return strtr($text, self::table());
    }

    /**
     * Whether the text carries anything from the presentation block.
     *
     * The question the sweeper asks before it writes a row back, and the fast path in
     * {@see self::deshape()}.
     */
    public static function isShaped(string $text): bool
    {
        return preg_match('/[\x{FE70}-\x{FEFC}]/u', $text) === 1;
    }

    /**
     * The pattern for asking the same question of a database column.
     *
     * Kept beside the table so the sweeper and the fold can never disagree about which
     * codepoints are in scope. PostgreSQL's regex, hence a bracket expression rather than a
     * PCRE — the escapes are the same.
     */
    public static function sqlPattern(): string
    {
        return '['.mb_chr(self::FIRST, 'UTF-8').'-'.mb_chr(self::LAST, 'UTF-8').']';
    }

    /**
     * The ranges spread one entry per codepoint, built once per process.
     *
     * @return array<string, string>
     */
    private static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];

        foreach (self::FOLDS as [$first, $last, $letter]) {
            for ($codepoint = $first; $codepoint <= $last; $codepoint++) {
                $table[mb_chr($codepoint, 'UTF-8')] = $letter;
            }
        }

        return self::$table = $table;
    }
}
