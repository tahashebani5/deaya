<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Actions\MatchNawrisGeography;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Putting their government and area ids onto our own cities and regions.
 *
 * **A city with no `nawris_government_id` refuses dispatch by name**, so until this mapping exists
 * the «إرسال للنورس» button fails for every destination. Doing it by hand means reading two lists
 * side by side and copying integers, which is exactly the job a computer should not be asked a
 * human to do.
 *
 * **What is stored is their name, verbatim — not their id.** `add-order` validates `government`
 * and `area` against the names in their own lists and refuses an id with «المدينة غير موجودة»;
 * the suffix is part of the string it wants, so «الحشان(s18)» is accepted where «الحشان» is not.
 * The columns are called `nawris_government_id` and `nawris_area_id` because they predate knowing
 * this.
 *
 * **Matching is by name, and a name is not an identifier.** Everything here is therefore built so
 * that a wrong guess is impossible: an exact match after Arabic normalisation or nothing at all,
 * never a nearest neighbour, and never over a mapping somebody already made.
 *
 * `Http::fake()` throughout — no test reaches the carrier.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisGeographyMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nawris.authentication_key', 'key');
        config()->set('services.nawris.main_client_code', 'client');
        config()->set('services.nawris.base_url', 'https://carrier.test/external-api/');
        config()->set('services.nawris.log_channel', 'null');
    }

    /**
     * @param  list<array{id: int, name: string}>  $governments
     * @param  array<string, list<array{id: int, name: string}>>  $areas  keyed by government id
     */
    private function carrierAnswers(array $governments, array $areas = []): void
    {
        $fakes = ['*get-government*' => Http::response(['result' => $governments], 200)];

        foreach ($areas as $government => $rows) {
            $fakes['*get-area/'.$government.'*'] = Http::response(['result' => $rows], 200);
        }

        // Anything we did not name is a government with no areas, not a failure.
        $fakes['*'] = Http::response(['result' => []], 200);

        Http::fake($fakes);
    }

    private function match(bool $apply = true): object
    {
        return app(MatchNawrisGeography::class)($apply);
    }

    // ── the cities ───────────────────────────────────────────────────────────────────────

    public function test_a_city_named_the_same_on_both_sides_is_mapped(): void
    {
        // Arrange
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => null]);

        // Act
        $this->match();

        // Assert — their string, not their integer.
        $this->assertSame('طرابلس', $city->fresh()->nawris_government_id);
    }

    public function test_a_name_that_differs_only_in_spelling_still_matches(): void
    {
        // Arrange — «الزاوية» against «الزاويه», and a hamza nobody types the same way twice.
        $this->carrierAnswers([['id' => 7, 'name' => 'الزاويه'], ['id' => 9, 'name' => 'إجدابيا']]);
        $zawiya = City::factory()->create(['name' => 'الزاوية', 'nawris_government_id' => null]);
        $ajdabiya = City::factory()->create(['name' => 'اجدابيا', 'nawris_government_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertSame('الزاويه', $zawiya->fresh()->nawris_government_id);
        $this->assertSame('إجدابيا', $ajdabiya->fresh()->nawris_government_id);
    }

    public function test_their_routing_suffix_is_not_part_of_the_name(): void
    {
        // Arrange — most of their list is written «اجدابيا(s444)»: a branch code they append,
        // not a second town. Matching on the raw string left 87 of our 95 cities unmapped.
        $this->carrierAnswers([
            ['id' => 8, 'name' => 'اجدابيا(s444)'],
            ['id' => 12, 'name' => 'الخمس(S7)'],
            ['id' => 15, 'name' => 'يفرن (s15)'],
        ]);
        $ajdabiya = City::factory()->create(['name' => 'أجدابيا', 'nawris_government_id' => null]);
        $khums = City::factory()->create(['name' => 'الخمس', 'nawris_government_id' => null]);
        $yafran = City::factory()->create(['name' => 'يفرن', 'nawris_government_id' => null]);

        // Act
        $this->match();

        // Assert — matched without the suffix, **stored with it**: their validator wants the
        // whole string back.
        $this->assertSame('اجدابيا(s444)', $ajdabiya->fresh()->nawris_government_id);
        $this->assertSame('الخمس(S7)', $khums->fresh()->nawris_government_id);
        $this->assertSame('يفرن (s15)', $yafran->fresh()->nawris_government_id);
    }

    public function test_the_suffix_is_stripped_in_square_brackets_too(): void
    {
        // Arrange — their areas bracket it differently from their governments: «إقزير[S5]» where
        // a city reads «الخمس(S7)». Covering only the round pair left all 61 areas of Misrata
        // unmatched against a list that contained every one of them.
        $this->carrierAnswers(
            [['id' => 11, 'name' => 'مصراتة']],
            ['11' => [['id' => 900, 'name' => 'إقزير[S5]'], ['id' => 901, 'name' => 'الجزيره[S5]']]],
        );
        $city = City::factory()->create(['name' => 'مصراتة', 'nawris_government_id' => null]);
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'إقزير', 'nawris_area_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertSame('إقزير[S5]', $region->fresh()->nawris_area_id);
    }

    public function test_an_underscore_where_we_write_a_space_still_matches(): void
    {
        // Arrange — their suburbs are «ضواحي_طرابلس»; ours are «ضواحي طرابلس». One keyboard, two
        // habits.
        $this->carrierAnswers([['id' => 232, 'name' => 'ضواحي_طرابلس']]);
        $city = City::factory()->create(['name' => 'ضواحي طرابلس', 'nawris_government_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertSame('ضواحي_طرابلس', $city->fresh()->nawris_government_id);
    }

    public function test_a_definite_article_on_one_side_only_still_matches(): void
    {
        // Arrange — «قصر الخيار» against «قصر خيار(s8)». A second pass, and still an exact
        // comparison: «ال» is dropped from both sides and the two strings must then be equal.
        $this->carrierAnswers([['id' => 8, 'name' => 'قصر خيار(s8)']]);
        $city = City::factory()->create(['name' => 'قصر الخيار', 'nawris_government_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertSame('قصر خيار(s8)', $city->fresh()->nawris_government_id);
    }

    public function test_two_of_theirs_that_collapse_to_one_are_left_for_a_human(): void
    {
        // Arrange — the article pass must never pick between «البيضاء» and «بيضاء». Ambiguous is
        // reported, exactly as absent is.
        $this->carrierAnswers([['id' => 7, 'name' => 'البيضاء'], ['id' => 8, 'name' => 'بيضاء']]);
        $city = City::factory()->create(['name' => 'بيضاءالجبل', 'nawris_government_id' => null]);

        // Act
        $report = $this->match();

        // Assert
        $this->assertNull($city->fresh()->nawris_government_id);
        $this->assertContains('بيضاءالجبل', $report->unmatchedCities);
    }

    public function test_an_office_pickup_city_is_not_a_carrier_destination_at_all(): void
    {
        // Arrange — «إستلام مكتب(قرجي)» is a counter of ours wearing a city's clothes. It is not
        // unmatched; it is not a destination, and reporting it would put permanent noise in a
        // list whose whole value is being a to-do.
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $counter = City::factory()->create([
            'name' => 'إستلام مكتب(قرجي)',
            'fulfilment_type' => FulfilmentType::OfficePickup,
            'nawris_government_id' => null,
        ]);

        // Act
        $report = $this->match();

        // Assert
        $this->assertNull($counter->fresh()->nawris_government_id);
        $this->assertNotContains('إستلام مكتب(قرجي)', $report->unmatchedCities);
    }

    public function test_a_city_they_do_not_have_is_reported_rather_than_guessed(): void
    {
        // Arrange — the nearest neighbour to «مصراتة» in their list is still not «مصراتة».
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $city = City::factory()->create(['name' => 'مصراتة', 'nawris_government_id' => null]);

        // Act
        $report = $this->match();

        // Assert
        $this->assertNull($city->fresh()->nawris_government_id);
        $this->assertContains('مصراتة', $report->unmatchedCities);
    }

    public function test_a_mapping_already_made_by_hand_is_never_overwritten(): void
    {
        // Arrange — somebody read both lists and decided; that decision outranks a name match.
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => 'شيء آخر']);

        // Act
        $this->match();

        // Assert
        $this->assertSame('شيء آخر', $city->fresh()->nawris_government_id);
    }

    // ── the regions ──────────────────────────────────────────────────────────────────────

    public function test_a_region_is_matched_within_its_own_city(): void
    {
        // Arrange — areas are read per government, so «الظهرة» of Tripoli is never confused with
        // an area of the same name somewhere else.
        $this->carrierAnswers(
            [['id' => 4, 'name' => 'طرابلس']],
            ['4' => [['id' => 204, 'name' => 'الظهرة'], ['id' => 205, 'name' => 'قرجي']]],
        );
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => null]);
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'الظهره', 'nawris_area_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertSame('الظهرة', $region->fresh()->nawris_area_id);
    }

    public function test_regions_of_a_city_they_do_not_have_are_left_alone(): void
    {
        // Arrange — with no government there is no area list to read.
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $city = City::factory()->create(['name' => 'مصراتة', 'nawris_government_id' => null]);
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'الظهرة', 'nawris_area_id' => null]);

        // Act
        $this->match();

        // Assert
        $this->assertNull($region->fresh()->nawris_area_id);
    }

    public function test_a_region_they_do_not_have_is_reported_by_its_city(): void
    {
        // Arrange
        $this->carrierAnswers(
            [['id' => 4, 'name' => 'طرابلس']],
            ['4' => [['id' => 205, 'name' => 'قرجي']]],
        );
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => null]);
        Region::factory()->create(['city_id' => $city->id, 'name' => 'الظهرة', 'nawris_area_id' => null]);

        // Act
        $report = $this->match();

        // Assert
        $this->assertContains('طرابلس — الظهرة', $report->unmatchedRegions);
    }

    // ── looking without touching ─────────────────────────────────────────────────────────

    public function test_a_dry_run_reports_everything_and_writes_nothing(): void
    {
        // Arrange — the whole point of a preview: the same answer, none of the consequences.
        $this->carrierAnswers(
            [['id' => 4, 'name' => 'طرابلس']],
            ['4' => [['id' => 204, 'name' => 'الظهرة']]],
        );
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => null]);
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'الظهرة', 'nawris_area_id' => null]);

        // Act
        $report = $this->match(apply: false);

        // Assert
        $this->assertSame(1, $report->matchedCities);
        $this->assertSame(1, $report->matchedRegions);
        $this->assertNull($city->fresh()->nawris_government_id);
        $this->assertNull($region->fresh()->nawris_area_id);
    }

    public function test_the_command_runs_the_same_matching(): void
    {
        // Arrange
        $this->carrierAnswers([['id' => 4, 'name' => 'طرابلس']]);
        $city = City::factory()->create(['name' => 'طرابلس', 'nawris_government_id' => null]);

        // Act
        $this->artisan('nawris:map-geography')->assertExitCode(0);

        // Assert
        $this->assertSame('طرابلس', $city->fresh()->nawris_government_id);
    }
}
