<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The company's editable defaults — one row, forever.
 *
 * A singleton rather than a key/value table: one setting does not justify an untyped `value`
 * column and the cast table that has to go with it, and a typed column is readable in a
 * migration and checkable by the database.
 *
 * **What lives here and what does not.** A value belongs here when the business edits it from a
 * screen and it must take effect without a deploy. Deploy-time infrastructure — timezones, disk
 * names, upload limits — stays in `config/`, where it is cached; the day a cached config
 * predated a key, every image upload was refused with «لا يمكن تجاوز 0 صور», and that is the
 * failure this table exists to stay clear of.
 *
 * The row is created by the migration, not by a seeder, so a box that migrated without seeding
 * still answers.
 */
#[UseFactory(CompanySettingFactory::class)]
#[Fillable(['investor_profit_share_percent'])]
class CompanySetting extends Model implements HasAuditTrail
{
    /** @use HasFactory<CompanySettingFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** The one row. Its id is part of the contract, not an accident. */
    public const SINGLETON_ID = 1;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'investor_profit_share_percent' => 'decimal:2',
        ];
    }
}
