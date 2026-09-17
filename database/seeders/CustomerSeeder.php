<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Actions\AllocateCustomerIdentifier;
use App\Domain\Customer\Actions\SyncCustomerShops;
use App\Domain\Customer\DTOs\CustomerShopData;
use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer book the business already had, out of «ارقام الزباين.xlsx».
 *
 * **Real records, not sample data** — 604 people who have actually ordered, which is why this
 * one belongs in production while the demo seeders do not.
 *
 * The spreadsheet is transcribed into {@see database/seeders/data/customers.php} by
 * `extract_customers.py` and committed; nothing here opens a workbook. A seeder that read the
 * sheet at runtime would only run where somebody happened to leave a copy of it, and production
 * has none. That script is also where rows are merged and dropped, and its header records the
 * count — this class imports what it is given and does not second-guess it.
 *
 * **Each customer keeps the number the business already calls them.** The sheet's `CODE` column
 * — A1, A2, … — has been on bags and said down telephones for years, so a row is seeded under
 * its own id and the code is that id with an 'A' in front, which is exactly what
 * {@see AllocateCustomerIdentifier} builds for everyone created since. Re-numbering 600
 * customers to suit the sequence would have been the tail wagging the dog; the sequence is
 * wound past the book instead, once, at the end of the run.
 *
 * **Idempotent, and it has to be.** Both `customers.code` and `customers.phone` are unique, so
 * a second run would otherwise fail partway and leave the book half-imported. A code already on
 * file is skipped, so this can be re-run after the sheet grows — which it will: 39 rows are
 * held back for having no telephone number, and land here the day somebody fills them in.
 */
class CustomerSeeder extends Seeder
{
    /**
     * Where a shop goes when the sheet's «Place» is not on the delivery map.
     *
     * Half the book says «استلام من المكتب», which is not an address, and another 50 rows name a
     * street or a landmark the map has never held — «الهاني», «البيفي», «كوبري الحديدي». The
     * alternative was to record no shop at all, and a shop is where the trading name and the
     * trade live, so that would have thrown away 146 shop names and 179 trades to protect a
     * `city_id` column. Collecting from the counter is the honest default for a customer whose
     * address nobody wrote down, and it is one correction on the customer's own screen — not a
     * customer who cannot be told apart from a blank row.
     *
     * قرجي rather than ولي العهد because the business named it as the default branch; the three
     * rows that say «ولي العهد» in so many words still resolve to that branch by name.
     */
    private const OFFICE_FALLBACK = 'إستلام مكتب(قرجي)';

    /**
     * The sheet's own trades, said its own way, against the list {@see BusinessFieldSeeder}
     * starts with.
     *
     * Only where one is plainly the same thing under another name. «شي إن», «ساده» and
     * «متجر إلكتروني» are three of the trades this shop prints for most and the starting list
     * names none of them, so they are created rather than flattened into «أخرى» — that list is
     * a first day's guess the business administers, not a closed set, and «بنفس نشاطهم» is the
     * whole point of the import.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'ملابس' => 'بيع ملابس',
        'عطور' => 'عطور ومستحضرات تجميل',
        'مستحضرات تجميل' => 'عطور ومستحضرات تجميل',
        'حلويات' => 'حلويات ومخابز',
        'مخابز' => 'حلويات ومخابز',
        'شحن' => 'شحن وتوصيل',
        'توصيل' => 'شحن وتوصيل',
        'مطعم' => 'مطاعم ومقاهي',
        'مطاعم' => 'مطاعم ومقاهي',
        'مقاهي' => 'مطاعم ومقاهي',
        'صيدلية' => 'صيدليات',
        'إلكترونيات' => 'إلكترونيات وهواتف',
        'الكترونيات' => 'إلكترونيات وهواتف',
        'هواتف' => 'إلكترونيات وهواتف',
        'بقالة' => 'بقالة وسوبرماركت',
        'مواد غذائية' => 'بقالة وسوبرماركت',
        'مكتبة' => 'مكتبات وقرطاسية',
    ];

    /** What a shop is filed under when the sheet's «نوع النشاط» is blank or unreadable. */
    private const UNKNOWN_FIELD = 'أخرى';

    /** Arabic spelling varies by who typed it; compare on a normalised form, store the original. */
    private static function fold(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $folded = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', trim($value)) ?? '';
        $folded = strtr($folded, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return preg_replace('/\s+/u', ' ', $folded) ?? '';
    }

    public function run(): void
    {
        $path = database_path('seeders/data/customers.php');

        // **Deliberately not in the repository.** It is 604 real people's names and telephone
        // numbers, and this repository is public — so the book is copied to each server out of
        // band and git-ignored here. Missing is therefore an ordinary state on a fresh clone,
        // and it says so rather than failing with a `require` that names no reason.
        if (! file_exists($path)) {
            $this->command?->warn(
                'seeders/data/customers.php is absent — the customer book is not in git '
                .'(it is personal data and this repository is public). Copy it to the server first.'
            );

            return;
        }

        /** @var list<array{code:string,name:string,phone:string,note:?string,shops:list<array{name:string,field:?string,place:?string}>}> $book */
        $book = require $path;

        $cities = City::query()->get(['id', 'name'])
            ->keyBy(fn (City $c): string => self::fold($c->name));
        $regions = Region::query()->get(['id', 'name', 'city_id'])
            ->keyBy(fn (Region $r): string => self::fold($r->name));
        $fields = BusinessField::query()->get(['id', 'name'])
            ->keyBy(fn (BusinessField $f): string => self::fold($f->name));

        $office = $cities->get(self::fold(self::OFFICE_FALLBACK))
            // Loud rather than clever. Without a branch to fall back on, 228 shops would be
            // silently dropped and the run would still report success.
            ?? throw new \RuntimeException(
                'The delivery map has no «'.self::OFFICE_FALLBACK.'» to file addressless shops '
                .'under. Run `php artisan db:seed --class=DeliveryLocationSeeder` first.'
            );

        // The notes column is «ملاحظة ثابته» — «كميات», «مغلق», «تصنيع» — and a note in this
        // system is said *by somebody*. It was the office that wrote them, so they are signed by
        // the administrator rather than left unattributed.
        $author = User::query()->orderBy('id')->first();

        $syncShops = app(SyncCustomerShops::class);
        $onFile = Customer::withTrashed()->pluck('code')->flip();

        $made = $skipped = $shops = $notes = 0;

        foreach ($book as $entry) {
            if ($onFile->has($entry['code'])) {
                $skipped++;

                continue;
            }

            $customer = new Customer([
                'name' => $entry['name'],
                'phone' => $entry['phone'],
                'is_active' => true,
            ]);

            // Assigned directly rather than mass-assigned: neither the key nor the code may ever
            // come from outside the domain. Here they come from the book, and they agree — the
            // id *is* the number printed in the code.
            $customer->id = (int) mb_substr($entry['code'], mb_strlen(AllocateCustomerIdentifier::PREFIX));
            $customer->code = $entry['code'];
            $customer->save();

            $syncShops($customer, array_map(
                fn (array $shop): CustomerShopData => $this->shopFor($shop, $cities, $regions, $fields, $office),
                $entry['shops'],
            ));

            $shops += count($entry['shops']);

            if ($entry['note'] !== null && $author !== null) {
                $note = new Comment(['body' => $entry['note']]);
                $note->user_id = $author->id;
                $customer->comments()->save($note);
                $notes++;
            }

            $onFile[$entry['code']] = true;
            $made++;
        }

        $this->windSequencePastTheBook();

        $this->command?->info(
            "customers: {$made} created, {$skipped} already on file, {$shops} shops, {$notes} notes"
        );
    }

    /**
     * One shop, placed on the delivery map.
     *
     * @param  array{name:string,field:?string,place:?string}  $shop
     * @param  Collection<string, City>  $cities
     * @param  Collection<string, Region>  $regions
     * @param  Collection<string, BusinessField>  $fields
     */
    private function shopFor(
        array $shop,
        Collection $cities,
        Collection $regions,
        Collection $fields,
        City $office,
    ): CustomerShopData {
        [$cityId, $regionId] = $this->placeFor($shop['place'], $cities, $regions, $office);

        return new CustomerShopData(
            name: $shop['name'],
            cityId: $cityId,
            regionId: $regionId,
            businessFieldId: $this->fieldFor($shop['field'], $fields),
        );
    }

    /**
     * The city and neighbourhood behind one of the sheet's «Place» values.
     *
     * Matched against the map three ways before giving up, because the sheet is what a clerk
     * typed and the map is what an export produced: exactly, then as a neighbourhood, then as
     * the longest map name the value contains — which is what turns «حي دمشق / ولي العهد» into
     * حي دمشق and «برج طرابلس» into طرابلس without a table of special cases.
     *
     * @param  Collection<string, City>  $cities
     * @param  Collection<string, Region>  $regions
     * @return array{0: int, 1: int|null}
     */
    private function placeFor(?string $place, Collection $cities, Collection $regions, City $office): array
    {
        $folded = self::fold($place);

        if ($folded === '') {
            return [$office->id, null];
        }

        if ($city = $cities->get($folded)) {
            return [$city->id, null];
        }

        // A region knows its city, which is the whole reason the delivery map is two levels.
        if ($region = $regions->get($folded)) {
            return [$region->city_id, $region->id];
        }

        // Longest wins: «النوفليين - زناته» contains both, and the more specific of the two is
        // the one worth recording.
        $best = null;
        foreach ($regions as $key => $region) {
            if (str_contains($folded, $key) && ($best === null || mb_strlen($key) > mb_strlen($best))) {
                $best = $key;
            }
        }

        if ($best !== null) {
            $region = $regions->get($best);

            return [$region->city_id, $region->id];
        }

        foreach ($cities as $key => $city) {
            if ($city->id !== $office->id && str_contains($folded, $key)) {
                return [$city->id, null];
            }
        }

        return [$office->id, null];
    }

    /**
     * مجال العمل, from the sheet's word for it.
     *
     * @param  Collection<string, BusinessField>  $fields
     */
    private function fieldFor(?string $raw, Collection $fields): int
    {
        $folded = self::fold($raw);

        // «نوع النشاط» empty, or «**». Filed under «أخرى» rather than left blank: the picker
        // holds that row precisely so an unrecorded trade has somewhere to sit, and a null here
        // reads as «لم يُحدَّد» on a screen that then cannot be filtered.
        if ($folded === '') {
            return $this->named(self::UNKNOWN_FIELD, $fields)->id;
        }

        if ($named = $fields->get($folded)) {
            return $named->id;
        }

        foreach (self::SYNONYMS as $sheet => $listed) {
            if ($folded === self::fold($sheet)) {
                return $this->named($listed, $fields)->id;
            }
        }

        // A trade the starting list never named — «شي إن», «ساده», «متجر إلكتروني», «إكسسوارات».
        // Added rather than dropped: these are the trades the business actually prints for, and
        // the picker is administered from the app. Sorted below the seeded rows, since the
        // seeder's own order is the intended one.
        $created = BusinessField::query()->create([
            'name' => trim((string) $raw),
            'is_active' => true,
            'sort_order' => ((int) BusinessField::query()->max('sort_order')) + 10,
        ]);

        $fields[$folded] = $created;

        return $created->id;
    }

    /**
     * A field from the starting list, created if an administrator has since removed it.
     *
     * @param  Collection<string, BusinessField>  $fields
     */
    private function named(string $name, Collection $fields): BusinessField
    {
        $folded = self::fold($name);

        if ($existing = $fields->get($folded)) {
            return $existing;
        }

        $created = BusinessField::query()->create([
            'name' => $name,
            'is_active' => true,
            'sort_order' => ((int) BusinessField::query()->max('sort_order')) + 10,
        ]);

        $fields[$folded] = $created;

        return $created;
    }

    /**
     * Moves the id sequence past the book, so the next customer created from the app gets the
     * first code the sheet never used.
     *
     * The rows above were inserted with ids of their own, which `nextval` knows nothing about —
     * without this, the very first customer added through the app would be handed id 1 and fail
     * on `customers_pkey`. `setval` to the table's own maximum, so re-running after the book has
     * grown is harmless, and so is running it on a database where the app is already ahead.
     *
     * PostgreSQL-specific, like {@see AllocateCustomerIdentifier} it exists to serve.
     */
    private function windSequencePastTheBook(): void
    {
        DB::statement(
            "select setval(pg_get_serial_sequence('customers', 'id'), "
            .'coalesce((select max(id) from customers), 1))'
        );
    }
}
