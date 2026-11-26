<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Treasury\Currency\Application\Commands\UpdateCurrencySettingsCommand;
use Modules\Treasury\Currency\Application\Handlers\GetCurrencySettingsHandler;
use Modules\Treasury\Currency\Application\Handlers\UpdateCurrencySettingsHandler;
use Modules\Treasury\Currency\Application\Queries\GetCurrencySettingsQuery;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Http\Controllers\Concerns\InteractsWithCurrency;
use Modules\Treasury\Currency\Http\Requests\UpdateCurrencySettingsRequest;
use Modules\Treasury\Currency\Http\Resources\CurrencySettingsResource;
use OpenApi\Attributes as OA;

final class CurrencySettingsController extends Controller
{
    use ApiResponse;
    use InteractsWithCurrency;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/settings/currencies', operationId: 'currencySettingsShow', summary: 'Currency settings of the company', description: 'Functional currency (from the company), active currencies, alert threshold of the daily movement, treatment of unrealised gains, VAT rate method and the optional revaluation accounts.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function show(GetCurrencySettingsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        return $this->successResponse(new CurrencySettingsResource($handler->handle(new GetCurrencySettingsQuery($this->tenantId()))), $this->message('settings'));
    }

    #[OA\Put(path: '/api/v1/settings/currencies', operationId: 'currencySettingsUpdate', summary: 'Update the currency settings', description: 'The functional currency is always active. 409 when a currency with open items or balances is deactivated, or when a Swiss company changes its VAT rate method within one tax period.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'Settings saved'), new OA\Response(response: 409, description: 'Currency in use or VAT method locked'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(UpdateCurrencySettingsRequest $request, UpdateCurrencySettingsHandler $handler): JsonResponse
    {
        Gate::authorize('updateSettings', ExchangeRate::class);

        $accounts = (array) $request->validated('revaluation_accounts', []);

        try {
            $settings = $handler->handle(new UpdateCurrencySettingsCommand(
                tenantId: $this->tenantId(),
                activeCurrencies: array_values((array) $request->validated('active_currencies')),
                alertThresholdPercent: $request->validated('alert_threshold_percent') === null ? null : (string) $request->validated('alert_threshold_percent'),
                gainTreatment: UnrealizedGainTreatment::from((string) $request->validated('unrealized_gain_treatment')),
                vatRateMethod: $request->validated('vat_rate_method') === null ? null : VatRateMethod::from((string) $request->validated('vat_rate_method')),
                gainAccountId: $accounts['unrealized_gain'] ?? null,
                lossAccountId: $accounts['unrealized_loss'] ?? null,
                gainProvisionAccountId: $accounts['unrealized_gain_provision'] ?? null,
                actorId: $this->userId(),
            ));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(new CurrencySettingsResource($settings), $this->message('settings_saved'));
    }
}
