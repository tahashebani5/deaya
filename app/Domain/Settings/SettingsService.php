<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Models\CompanySetting;

/**
 * The door to the company's editable defaults.
 *
 * Other contexts ask this rather than reaching for the model, so the day the single row becomes
 * a key/value table nothing outside here notices.
 *
 * **Nothing caches the answer.** It is one indexed read on a one-row table, taken at the moment
 * a deal is created and never again for that deal; a cache would buy nothing and would need
 * forgetting in three places, which is exactly how `CreateRole`, `UpdateRole` and `DeleteRole`
 * each ended up calling `forgetCachedPermissions()`.
 */
final class SettingsService
{
    public function current(): CompanySetting
    {
        return CompanySetting::query()->findOrFail(CompanySetting::SINGLETON_ID);
    }

    /**
     * The share of a deal's profit that goes to its investors, as a decimal string.
     *
     * A **default for a new deal only**. Once a deal is created the figure lives on the deal and
     * is never read from here again — so changing this tomorrow cannot move a closed deal's
     * numbers, which is the whole reason it is safe for the business to edit.
     */
    public function investorProfitSharePercent(): string
    {
        return (string) $this->current()->investor_profit_share_percent;
    }

    public function update(string $investorProfitSharePercent, ?int $actorId): CompanySetting
    {
        $settings = $this->current();

        $settings->fill(['investor_profit_share_percent' => $investorProfitSharePercent]);
        $settings->updated_by = $actorId;
        $settings->save();

        return $settings;
    }
}
