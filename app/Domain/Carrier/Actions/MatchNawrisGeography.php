<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\DTOs\GeographyMatchReport;
use App\Domain\Carrier\Exceptions\CityHasNoNawrisMapping;
use App\Domain\Carrier\Support\ArabicName;
use App\Domain\Carrier\Support\NawrisClient;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;

/**
 * Reads their government and area lists and writes **their names** onto our own cities and regions.
 *
 * **Their name, verbatim — not their id.** `add-order` validates `government` and `area` against
 * the names in those lists: an id comes back «المدينة غير موجودة», and the routing suffix is part
 * of the string it wants, so «الحشان(s18)» is accepted where «الحشان» is not. The columns are
 * still called `nawris_government_id` and `nawris_area_id` because they were added before anyone
 * had called the API.
 *
 * **Why this is not a screen.** A city with no `nawris_government_id` refuses dispatch by name —
 * see {@see CityHasNoNawrisMapping} — so before the first parcel
 * every destination has to be mapped. Doing it by hand is reading two lists side by side and
 * copying integers, once per town, and getting one wrong sends parcels to the wrong place with
 * nothing on our side reading as an error.
 *
 * **Two exact passes, then one guess that says it is a guess.** {@see ArabicName} folds the
 * spellings nobody means differently and the two exact passes demand equality after it; only when
 * both find nothing does {@see nearest} look for a name a letter or two away. The failure mode of
 * that third pass is a parcel delivered to another town, which no one finds out about until a
 * customer calls — so it is deliberately narrow, it refuses ties outright, and every pair it
 * produces is listed in the report under «مطابقات تقريبية» for somebody to read. **Its output is
 * a proposal, not a fact**, and the run that prints it is the last chance to catch «بن سعيد»
 * quietly becoming «بن سعيب».
 *
 * **Never overwrites.** A city that already carries an id was mapped by somebody who looked, and
 * a name match is a weaker fact than a decision. Re-running is therefore safe, and is how the
 * mapping is finished: map what you can, phone about the rest, run it again.
 *
 * Areas are read per government, so an area name is only ever matched inside the city it belongs
 * to — two towns may both have a «وسط البلد».
 */
final class MatchNawrisGeography
{
    public function __construct(private readonly NawrisClient $client) {}

    /**
     * @param  bool  $apply  false previews: the same report, nothing written
     */
    public function __invoke(bool $apply = true): GeographyMatchReport
    {
        [$strict, $loose] = $this->byName($this->client->governments());

        $matchedCities = 0;
        $matchedRegions = 0;
        $alreadyMapped = 0;
        $unmatchedCities = [];
        $unmatchedRegions = [];
        $approximate = [];

        $cities = City::query()
            // **An «استلام مكتب» city is not an unmapped destination, it is not a destination.**
            // Two of ours are counters of our own wearing a city's clothes; dispatch refuses them
            // by name anyway, and listing them as unmatched would put permanent noise in a report
            // whose whole worth is being a to-do list that can reach empty.
            ->where('fulfilment_type', FulfilmentType::Delivery)
            ->with('regions')
            ->orderBy('name')
            ->get();

        foreach ($cities as $city) {
            // A city mapped by hand carries their *name*, and `get-area` wants their id — so the
            // row is looked up either way, and the mapping is simply not rewritten.
            $their = $this->theirRowFor(
                $this->mapped($city->nawris_government_id) ?? $city->name,
                $strict,
                $loose,
            );

            if ($their === null) {
                // Their side has never heard of this town; its regions cannot be read either.
                $unmatchedCities[] = (string) $city->name;

                continue;
            }

            if ($their['approximate']) {
                $approximate[] = 'مدينة: '.$city->name.' ← '.$their['name'];
            }

            if ($this->mapped($city->nawris_government_id) !== null) {
                $alreadyMapped++;
            } else {
                $matchedCities++;

                if ($apply) {
                    $city->forceFill(['nawris_government_id' => $their['name']])->save();
                }
            }

            [$regions, $missing, $guessed] = $this->matchRegions($city, $their['id'], $apply);

            $matchedRegions += $regions;
            $unmatchedRegions = [...$unmatchedRegions, ...$missing];
            $approximate = [...$approximate, ...$guessed];
        }

        return new GeographyMatchReport(
            matchedCities: $matchedCities,
            matchedRegions: $matchedRegions,
            unmatchedCities: $unmatchedCities,
            unmatchedRegions: $unmatchedRegions,
            alreadyMappedCities: $alreadyMapped,
            approximateMatches: $approximate,
        );
    }

    /**
     * @param  string  $government  their **id** for the city — what `get-area` is keyed by
     * @return array{int, list<string>, list<string>}
     */
    private function matchRegions(City $city, string $government, bool $apply): array
    {
        $unmapped = $city->regions->filter(fn (Region $region) => $this->mapped($region->nawris_area_id) === null);

        // One HTTP call per city is already the cost of this; making it for a city with nothing
        // left to map would be paying it for no reason.
        if ($unmapped->isEmpty()) {
            return [0, [], []];
        }

        [$strict, $loose] = $this->byName($this->client->areas($government));

        $matched = 0;
        $missing = [];
        $guessed = [];

        foreach ($unmapped as $region) {
            $area = $this->theirRowFor($region->name, $strict, $loose);

            if ($area === null) {
                // Named with its city, because «الظهرة» alone does not say which one.
                $missing[] = $city->name.' — '.$region->name;

                continue;
            }

            $matched++;

            if ($area['approximate']) {
                $guessed[] = 'منطقة: '.$city->name.' — '.$region->name.' ← '.$area['name'];
            }

            if ($apply) {
                $region->forceFill(['nawris_area_id' => $area['name']])->save();
            }
        }

        return [$matched, $missing, $guessed];
    }

    /**
     * Their rows keyed by the normalised name.
     *
     * A name they list twice keeps the first: a duplicate is their data problem, and picking the
     * later one silently would make this run's answer depend on their ordering.
     *
     * **Their whole row, not just the name.** Both are needed and for different reasons: the name
     * is what `add-order` validates against and is therefore what we store, while `get-area` is
     * keyed by the id — so a city matched by name is read for its areas by number.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>}
     */
    private function byName(array $rows): array
    {
        $keyed = [];
        $loose = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $raw = isset($row['name']) ? (string) $row['name'] : '';
            $name = ArabicName::normalize($raw);

            if ($name === '' || isset($keyed[$name])) {
                continue;
            }

            $keyed[$name] = $row;

            // The article pass, built beside the strict one so a collision can be seen. Two of
            // their names that collapse together are removed from it entirely — «البيضاء» and
            // «بيضاء» may be two towns, and picking one silently is the failure this whole class
            // is arranged to avoid.
            $bare = ArabicName::withoutArticles($raw);

            if (isset($loose[$bare])) {
                $ambiguous[$bare] = true;

                continue;
            }

            $loose[$bare] = $row;
        }

        return [$keyed, array_diff_key($loose, $ambiguous)];
    }

    /**
     * Their row for one of ours, or null.
     *
     * Three passes, each only reached when the one before it found nothing: exact after
     * normalisation, exact again without the articles, and finally {@see nearest} — the only one
     * of the three that is a guess, and the only one that sets `approximate`.
     *
     * @param  array<string, array<string, mixed>>  $strict
     * @param  array<string, array<string, mixed>>  $loose
     * @return array{id: string, name: string, approximate: bool}|null
     */
    private function theirRowFor(?string $ours, array $strict, array $loose): ?array
    {
        $row = $strict[ArabicName::normalize($ours)]
            ?? $loose[ArabicName::withoutArticles($ours)]
            ?? null;

        $approximate = false;

        if ($row === null) {
            $row = $this->nearest((string) $ours, $strict);
            $approximate = $row !== null;
        }

        if ($row === null) {
            return null;
        }

        $name = isset($row['name']) ? (string) $row['name'] : '';

        return $name === ''
            ? null
            : [
                'id' => isset($row['id']) ? (string) $row['id'] : '',
                'name' => $name,
                'approximate' => $approximate,
            ];
    }

    /**
     * The one name of theirs close enough to ours to be a misspelling of it — or null.
     *
     * **Two rules, and the second matters more than the first.**
     *
     * *Close enough* is one letter per five, and never fewer than one: «صلاج الدين» reaches
     * «صلاح الدين» and «ززواغة» reaches «زواغة», while «تساوة» does not reach «هراوة» three
     * letters away. Scaling with length is what keeps a short name strict — on four letters a
     * fixed budget of two would put half the alphabet within reach.
     *
     * *And unambiguous.* Two of their names tied at the best distance means neither is chosen.
     * A tie is precisely the case where a guess is worth least and costs most, and picking one by
     * array order would make the answer depend on how they happened to sort their list.
     *
     * **This pass is a guess, and it is reported as one.** {@see GeographyMatchReport::$approximateMatches}
     * carries every pair it produced so the run can be read by somebody who knows the country;
     * an approximate match on a *city* puts a parcel in another town, and the only defence
     * against that is that a human sees the sentence «س ← ص» before parcels start moving.
     *
     * @param  array<string, array<string, mixed>>  $strict  their rows keyed by normalised name
     * @return array<string, mixed>|null
     */
    private function nearest(string $ours, array $strict): ?array
    {
        $name = ArabicName::normalize($ours);

        if ($name === '') {
            return null;
        }

        $budget = max(1, intdiv(mb_strlen($name), 5));

        $best = null;
        $bestDistance = $budget + 1;
        $tied = false;

        foreach ($strict as $theirs => $row) {
            $distance = ArabicName::distance($name, $theirs);

            if ($distance > $budget) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = $row;
                $bestDistance = $distance;
                $tied = false;

                continue;
            }

            if ($distance === $bestDistance) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    /** An id that is present and not an empty string — the same test dispatch makes. */
    private function mapped(?string $id): ?string
    {
        return $id !== null && trim($id) !== '' ? $id : null;
    }
}
