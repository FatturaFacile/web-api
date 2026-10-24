<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Services\Inventory\LabelPresetRegistry;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Typed, whitelisted per-tenant preferences stored under `tenants.settings.preferences.<group>.<key>`.
 */
class TenantPreferences
{
    public const GROUPS = ['invoice', 'email', 'sdi', 'work_orders', 'company', 'labels', 'inventory', 'banking', 'accounting'];

    /**
     * Preference keys that hold a group of options, merged option by option when updated.
     *
     * @var array<string, array<int, string>>
     */
    private const NESTED_OPTIONS = ['banking' => ['reconciliation', 'ledger_defaults'], 'accounting' => ['accounts', 'bank_accounts']];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * An IBAN without spaces, in upper case.
     */
    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /**
     * Validation rules for the keys of a preference group (null clears a preference).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $group): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        return match ($group) {
            'invoice' => [
                'default_payment_term_id' => ['nullable', 'uuid', Rule::exists('payment_terms', 'id')->where('tenant_id', $tenantId)],
                'footer_text' => ['nullable', 'string', 'max:1000'],
                'reminder_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'reminder_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'reminder_max_level' => ['nullable', 'integer', 'min:1', 'max:3'],
            ],
            'email' => [
                'sender_name' => ['nullable', 'string', 'max:100'],
                'reply_to' => ['nullable', 'email', 'max:255'],
                'bcc' => ['nullable', 'email', 'max:255'],
                'signature' => ['nullable', 'string', 'max:2000'],
            ],
            'sdi' => [
                'send_enabled' => ['nullable', 'boolean'],
            ],
            'company' => [
                'street' => ['nullable', 'string', 'max:70'],
                'house_number' => ['nullable', 'string', 'max:16'],
                'postal_code' => ['nullable', 'string', 'max:16'],
                'city' => ['nullable', 'string', 'max:35'],
                'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
                'address_line_1' => ['nullable', 'string', 'max:70'],
                'address_line_2' => ['nullable', 'string', 'max:70'],
            ],
            'labels' => array_merge(
                [
                    'default_preset' => ['nullable', 'string', 'max:50'],
                    'custom_presets' => ['nullable', 'array', 'max:20'],
                    'custom_presets.*' => ['array'],
                    'custom_presets.*.name' => ['required', 'string', 'max:100'],
                ],
                LabelPresetRegistry::geometryRules('custom_presets.*'),
                LabelPresetRegistry::contentOptionRules('custom_presets.*.defaults'),
            ),
            'accounting' => [
                'accounts' => ['nullable', 'array'],
                'accounts.receivables_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.payables_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.revenue_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.output_vat_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.cost_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.input_vat_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.bank_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'accounts.rounding_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                // Ledger account of every bank account, by IBAN: {"IT60X0542811101000000123456": "1001"}.
                'bank_accounts' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                    foreach (array_keys((array) $value) as $iban) {
                        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', self::normalizeIban((string) $iban)) !== 1) {
                            $fail(__('accounting.errors.invalid_iban', ['iban' => $iban]));
                        }
                    }
                }],
                'bank_accounts.*' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
            ],
            'banking' => [
                'reconciliation' => ['nullable', 'array'],
                'reconciliation.auto_match_tolerance_amount' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'reconciliation.max_fee_deduction_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
                'reconciliation.allow_partial_reconciliation' => ['nullable', 'boolean'],
                'ledger_defaults' => ['nullable', 'array'],
                'ledger_defaults.tax_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'ledger_defaults.payroll_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'ledger_defaults.bank_charges_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
                'ledger_defaults.auto_categorize_standard_codes' => ['nullable', 'boolean'],
            ],
            'inventory' => [
                'auto_deactivate_on_zero_stock' => ['nullable', 'boolean'],
                'sync_deactivation_to_ecommerce' => ['nullable', 'boolean'],
            ],
            'work_orders' => [
                'auto_invoice' => ['nullable', 'boolean'],
                'auto_email' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = $this->tenantContext->getTenant()?->settings['preferences'] ?? [];

        return collect(self::GROUPS)->mapWithKeys(fn (string $group): array => [$group => $stored[$group] ?? []])->all();
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        return $this->all()[$group][$key] ?? $default;
    }

    /**
     * Merge values into a group; null values remove the preference.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(string $group, array $values): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($group, $values, $tenantId): array {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenantId);
            $settings = $tenant->settings ?? [];
            $current = $settings['preferences'][$group] ?? [];

            foreach ($values as $key => $value) {
                if (in_array($key, self::NESTED_OPTIONS[$group] ?? [], true) && is_array($value)) {
                    // The nested options are merged one by one; null removes a single option.
                    if ($key === 'bank_accounts') {
                        $value = collect($value)->mapWithKeys(fn (mixed $account, string|int $iban): array => [self::normalizeIban((string) $iban) => $account])->all();
                    }

                    $merged = array_filter(array_merge($current[$key] ?? [], $value), fn (mixed $option): bool => $option !== null);

                    if ($merged === []) {
                        unset($current[$key]);
                    } else {
                        $current[$key] = $merged;
                    }

                    continue;
                }

                if ($value === null) {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            $settings['preferences'][$group] = $current;
            $tenant->update(['settings' => $settings]);
            $this->tenantContext->setTenant($tenant);

            return $current;
        });
    }
}
