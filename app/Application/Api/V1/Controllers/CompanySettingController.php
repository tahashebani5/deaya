<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Controller;
use App\Domain\Settings\SettingsService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company settings
 *
 * The handful of defaults the business edits from a screen rather than from a deploy.
 *
 * **A default is not a rate that reaches back.** `investor_profit_share_percent` seeds a new
 * deal and is never read again for that deal, so changing it tomorrow decides what the next deal
 * is born with and moves nothing that has already been agreed or paid.
 */
class CompanySettingController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Read the company settings
     */
    public function show(): JsonResponse
    {
        $settings = $this->settings->current();

        return $this->success([
            'investor_profit_share_percent' => (string) $settings->investor_profit_share_percent,
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Update the company settings
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'investor_profit_share_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'investor_profit_share_percent.required' => 'نسبة المستثمرين مطلوبة',
            'investor_profit_share_percent.max' => 'النسبة لا تتجاوز 100',
        ]);

        $settings = $this->settings->update(
            number_format((float) $validated['investor_profit_share_percent'], 2, '.', ''),
            $request->user()?->id,
        );

        return $this->success([
            'investor_profit_share_percent' => (string) $settings->investor_profit_share_percent,
        ], 'تم تحديث الإعدادات — تسري على الصفقات الجديدة وحدها');
    }
}
