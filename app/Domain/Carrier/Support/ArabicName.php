<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Support;

/**
 * One spelling of an Arabic place name, so two lists written by different people can be compared.
 *
 * **Only the differences nobody means.** «الزاوية» and «الزاويه» are the same town; so are
 * «إجدابيا» and «اجدابيا», and «طرابلس  الكبرى» with two spaces. None of those is a decision
 * somebody made — they are the keyboard, the hamza, and a stray space. Everything else is left
 * exactly as written, because a normaliser that reaches further starts merging places that are
 * genuinely different.
 *
 * **The carrier's own routing suffix goes too.** Most of their list reads «اجدابيا(s444)»,
 * «الخمس(S7)», «يفرن (s15)» — a branch code appended to the town, not part of its name. Leaving
 * it in left 87 of our 95 cities unmatched against a list that plainly contained them. **Their
 * areas bracket it squarely** — «إقزير[S5]» — which is why both pairs are matched here and not
 * just the one the cities happened to use.
 *
 * **{@see normalize} and {@see withoutArticles} are still exact comparisons.** Neither measures
 * anything; they rewrite one spelling and then demand equality. The edit distance lives in
 * {@see distance} and is used by nothing here — {@see \App\Domain\Carrier\Actions\MatchNawrisGeography}
 * reaches for it only after both exact passes have failed, and carries the threshold and the
 * tie-breaking rule itself, because those are a matching policy and not a fact about spelling.
 */
final class ArabicName
{
    /** The vowel marks and the tatweel, none of which change which town is meant. */
    private const STRIPPED = ['ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ', 'ٰ', 'ـ'];

    /** The letters typed a dozen ways for one sound. */
    private const FOLDED = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
    ];

    public static function normalize(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        // Their branch code, round, square or curly, in either alphabet's brackets. Anchored to
        // the end so a genuine parenthetical mid-name — if one ever appears — is left alone.
        // **The curly pair is not hypothetical**: «زاوية المحجوب  {s5}» is how they write one of
        // Misrata's, and it was the only thing keeping a name we spell identically off the map.
        $name = (string) preg_replace('/\s*[(（\[［{｛][^)）\]］}｝]*[)）\]］}｝]\s*$/u', '', $name);

        // The same code with the brackets simply forgotten — «السوانيS20». Narrow on purpose: an
        // `s` and digits, and nothing else, so it can only ever eat a routing code. No Arabic
        // place name ends in a Latin letter followed by a number.
        $name = (string) preg_replace('/\s*[sS]\s*\d+\s*$/u', '', $name);

        $name = str_replace(self::STRIPPED, '', $name);
        $name = strtr($name, self::FOLDED);

        // Any run of whitespace is one space — including the non-breaking kind that arrives in
        // data pasted out of a browser, and the underscore they join words with: their suburbs
        // are «ضواحي_طرابلس» where ours are «ضواحي طرابلس».
        $name = (string) preg_replace('/[\s_]+/u', ' ', str_replace("\u{00A0}", ' ', $name));

        return trim($name);
    }

    /**
     * The same name with «ال» off the front of every word.
     *
     * **A second exact comparison, not a fuzzy one.** «قصر الخيار» and «قصر خيار» are one place
     * written by two people; nothing here measures distance or picks a nearest neighbour. It is
     * used only after {@see normalize} has failed to find anything, and only when exactly one of
     * their names collapses to it — two that collapse together are reported rather than chosen
     * between, because «البيضاء» and «بيضاء» could be two towns.
     */
    public static function withoutArticles(?string $name): string
    {
        $words = explode(' ', self::normalize($name));

        // Left alone when the word *is* «ال…» and nothing else would remain.
        $stripped = array_map(
            static fn (string $word): string => mb_strlen($word) > 3 && str_starts_with($word, 'ال')
                ? mb_substr($word, 2)
                : $word,
            $words,
        );

        return implode(' ', $stripped);
    }

    /**
     * Edit distance between two already-normalised names, **in letters rather than bytes**.
     *
     * PHP's own `levenshtein()` counts bytes, and every Arabic letter is two of them. What that
     * costs is not a uniform doubling: «برقه»/«برقن» comes back as 1 because both letters happen
     * to share a lead byte, while a substitution across a block boundary comes back as 2. A
     * threshold built on that number is quietly stricter for some spellings than others, for a
     * reason nobody reading the threshold could ever guess. This counts letters.
     *
     * Wagner–Fischer over two rows. A place name is a handful of characters, so the quadratic
     * cost is irrelevant and the plainness is worth more than a cleverer algorithm.
     */
    public static function distance(string $a, string $b): int
    {
        /** @var list<string> $left */
        $left = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        /** @var list<string> $right */
        $right = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $width = count($right);

        if ($left === []) {
            return $width;
        }

        $previous = range(0, $width);

        foreach ($left as $i => $letter) {
            $current = [$i + 1];

            foreach ($right as $j => $other) {
                $current[] = min(
                    $previous[$j + 1] + 1,               // deletion
                    $current[$j] + 1,                    // insertion
                    $previous[$j] + ($letter === $other ? 0 : 1), // substitution
                );
            }

            $previous = $current;
        }

        return $previous[$width];
    }
}
