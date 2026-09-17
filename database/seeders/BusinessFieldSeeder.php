<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customer\Models\BusinessField;
use Illuminate\Database\Seeder;

/**
 * A starting list of trades, so the field on the customer form is not an empty dropdown on the
 * first day.
 *
 * **It is a starting point, not a policy.** The list is administered from the app — added to,
 * renamed, reordered, deactivated — and nothing in the code reads any of these names. That is
 * why it is safe to seed a guess: the first week of real customers corrects it.
 *
 * Idempotent, and it does not fight the administrator: rows are matched by name and only
 * *created*, so a field renamed or deactivated from the screen stays that way when this runs
 * again. Only `sort_order` is refreshed, because the order here is the intended one.
 */
class BusinessFieldSeeder extends Seeder
{
    /**
     * Ordered as a staff member would scan them: the trades this shop actually prints for
     * first, «أخرى» last so it is not the easy answer.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'شحن وتوصيل',
        'تجارة عامة',
        'بيع ملابس',
        'مطاعم ومقاهي',
        'حلويات ومخابز',
        'عطور ومستحضرات تجميل',
        'صيدليات',
        'إلكترونيات وهواتف',
        'بقالة وسوبرماركت',
        'مكتبات وقرطاسية',
        'أخرى',
    ];

    public function run(): void
    {
        foreach (self::FIELDS as $index => $name) {
            BusinessField::query()->updateOrCreate(
                ['name' => $name],
                ['sort_order' => ($index + 1) * 10],
            );
        }
    }
}
