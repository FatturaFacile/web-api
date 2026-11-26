<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Treasury\Currency\Application\Commands\UpdateCurrencySettingsCommand;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Policies\FxVatPolicyResolver;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyCode;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySetting;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyUsageChecker;
use Modules\Treasury\Currency\Infrastructure\Persistence\EffectiveCurrencySettings;

/**
 * Saves the currency settings. The functional currency of the company (Mod 001) is always active. A currency with open items
 * or balances cannot be deactivated (409). A Swiss company keeps its VAT rate method for at least one tax period.
 */
final readonly class UpdateCurrencySettingsHandler
{
    public function __construct(
        private CurrencySettingsRepository $settings,
        private CurrencyUsageChecker $usage,
        private FxVatPolicyResolver $vatPolicies,
    ) {}

    /**
     * @throws CurrencyDomainException
     */
    public function handle(UpdateCurrencySettingsCommand $command): EffectiveCurrencySettings
    {
        $current = $this->settings->effective($command->tenantId);
        $active = array_values(array_unique(array_map(static fn (string $code): string => CurrencyCode::of($code)->value, [...$command->activeCurrencies, $current->functionalCurrency])));
        sort($active);

        $removed = array_values(array_diff($current->activeCurrencies, $active));
        $blocked = $this->usage->inUse($command->tenantId, $removed);

        if ($blocked !== []) {
            throw new CurrencyDomainException(__('currency_fx.errors.currency_in_use', ['currencies' => implode(', ', $blocked)]), 'currency_in_use', 409, 'active_currencies');
        }

        $this->assertAccounts($command);

        $policy = $this->vatPolicies->forCountry($current->country);
        $vatMethod = $command->vatRateMethod ?? $current->vatRateMethod;

        if (! in_array($vatMethod, $policy->allowedMethods(), true)) {
            throw new CurrencyDomainException(__('currency_fx.errors.vat_method_not_allowed', ['country' => $policy->country()]), 'vat_method_not_allowed', 422, 'vat_rate_method');
        }

        $existing = $this->settings->find($command->tenantId);
        $methodChanged = $vatMethod !== ($existing->vat_rate_method ?? $current->vatRateMethod);
        $lastChange = $existing === null || $existing->vat_method_changed_at === null ? null : CarbonImmutable::instance($existing->vat_method_changed_at);

        if ($methodChanged && ! $policy->canChangeMethod($lastChange, CarbonImmutable::now())) {
            throw new CurrencyDomainException(__('currency_fx.errors.vat_method_locked'), 'vat_method_locked', 409, 'vat_rate_method');
        }

        DB::transaction(function () use ($command, $active, $vatMethod, $methodChanged, $existing): void {
            $setting = $existing ?? new CurrencySetting;
            $setting->forceFill([
                'tenant_id' => $command->tenantId,
                'active_currencies' => $active,
                'alert_threshold_percent' => $command->alertThresholdPercent,
                'unrealized_gain_treatment' => $command->gainTreatment->value,
                'vat_rate_method' => $vatMethod->value,
                'vat_method_changed_at' => $methodChanged || $existing === null ? CarbonImmutable::now() : $existing->vat_method_changed_at,
                'unrealized_gain_account_id' => $command->gainAccountId,
                'unrealized_loss_account_id' => $command->lossAccountId,
                'unrealized_gain_provision_account_id' => $command->gainProvisionAccountId,
                'updated_by' => $command->actorId,
            ]);

            if ($existing === null) {
                $setting->forceFill(['created_by' => $command->actorId]);
            }

            $setting->save();
        });

        return $this->settings->effective($command->tenantId);
    }

    private function assertAccounts(UpdateCurrencySettingsCommand $command): void
    {
        foreach (['unrealized_gain_account_id' => $command->gainAccountId, 'unrealized_loss_account_id' => $command->lossAccountId, 'unrealized_gain_provision_account_id' => $command->gainProvisionAccountId] as $field => $id) {
            if ($id !== null && ! ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $command->tenantId)->whereKey($id)->exists()) {
                throw new CurrencyDomainException(__('currency_fx.errors.account_not_found'), 'account_not_found', 422, $field);
            }
        }
    }
}
