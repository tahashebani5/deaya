<?php

declare(strict_types=1);

namespace App\Domain\Carrier\DTOs;

use App\Domain\Carrier\Actions\MatchNawrisGeography;

/**
 * What a run of {@see MatchNawrisGeography} did, and what it could not.
 *
 * **The unmatched lists are the useful half.** A count of successes tells nobody what to do next;
 * a list of the towns their side has never heard of is a morning's work with a phone, and it is
 * the only way the mapping ever reaches complete.
 */
final class GeographyMatchReport
{
    /**
     * @param  list<string>  $unmatchedCities  our city names with no counterpart
     * @param  list<string>  $unmatchedRegions  «city — region», so a name says where it lives
     * @param  list<string>  $approximateMatches  «ours ← theirs» for every name matched by
     *                                           distance rather than equality — a guess each, and
     *                                           the list a human has to read before parcels move
     */
    public function __construct(
        public readonly int $matchedCities = 0,
        public readonly int $matchedRegions = 0,
        public readonly array $unmatchedCities = [],
        public readonly array $unmatchedRegions = [],
        public readonly int $alreadyMappedCities = 0,
        public readonly array $approximateMatches = [],
    ) {}
}
