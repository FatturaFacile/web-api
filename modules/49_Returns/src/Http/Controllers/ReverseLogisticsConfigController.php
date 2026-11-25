<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Services\ReturnsSettingsService;
use OpenApi\Attributes as OA;

/**
 * Exposes backend-seeded UI metadata, labels, grading definitions, and fiscal rules for Mod 232.
 */
final class ReverseLogisticsConfigController extends Controller
{
    use InteractsWithReturns;

    public function __construct(
        private readonly ReturnsSettingsService $settings,
    ) {}

    #[OA\Get(path: '/api/v1/returns/reverse-logistics/config', operationId: 'getReverseLogisticsConfig', summary: 'Get seeded reverse logistics configuration, grading options, and tax rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Reverse logistics config'),
    ])]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', CustomerReturn::class);
        $tenantId = $this->tenantId();
        $setting = $this->settings->settings($tenantId);

        return response()->json([
            'data' => [
                'settings' => [
                    'return_window_days' => $setting->return_window_days,
                    'warranty_window_days' => $setting->warranty_window_days,
                    'default_restocking_fee_percent' => $setting->default_restocking_fee_percent,
                    'advance_replacement_sla_days' => $setting->advance_replacement_sla_days,
                    'outlet_devaluation_grade_b_percent' => $setting->outlet_devaluation_grade_b_percent,
                    'outlet_devaluation_grade_c_percent' => $setting->outlet_devaluation_grade_c_percent,
                ],
                'reason_codes' => collect($this->settings->reasonCodes($tenantId, true))->map(static fn ($r): array => [
                    'code' => $r->code,
                    'label' => __('returns_rl.reasons.'.$r->code) !== 'returns_rl.reasons.'.$r->code
                        ? __('returns_rl.reasons.'.$r->code)
                        : $r->label,
                    'is_defect' => $r->is_defect,
                ])->values()->all(),
                'grades' => [
                    [
                        'code' => 'grade_a',
                        'label' => __('returns_rl.grades.grade_a'),
                        'default_disposition' => 'restock_primary',
                        'default_devaluation_percent' => '0.00',
                    ],
                    [
                        'code' => 'grade_b',
                        'label' => __('returns_rl.grades.grade_b'),
                        'default_disposition' => 'convert_b_stock',
                        'default_devaluation_percent' => (string) $setting->outlet_devaluation_grade_b_percent,
                    ],
                    [
                        'code' => 'grade_c',
                        'label' => __('returns_rl.grades.grade_c'),
                        'default_disposition' => 'convert_b_stock',
                        'default_devaluation_percent' => (string) $setting->outlet_devaluation_grade_c_percent,
                    ],
                    [
                        'code' => 'scrap_or_supplier',
                        'label' => __('returns_rl.grades.scrap_or_supplier'),
                        'default_disposition' => 'return_to_supplier',
                        'default_devaluation_percent' => '100.00',
                    ],
                ],
                'tax_rules' => [
                    'it_art26_summary' => __('returns_rl.tax_rules.it_art26_summary'),
                    'it_art26_n2_2_explanation' => __('returns_rl.tax_rules.it_art26_n2_2_explanation'),
                    'ch_art41_summary' => __('returns_rl.tax_rules.ch_art41_summary'),
                ],
            ],
        ]);
    }
}
