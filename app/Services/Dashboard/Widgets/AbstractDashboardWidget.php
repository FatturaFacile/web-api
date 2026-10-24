<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared behaviour of the built-in widgets: titles from the language files, settings validation against the
 * configurable options and the tenant helpers.
 */
abstract class AbstractDashboardWidget implements DashboardWidgetInterface
{
    public function __construct(protected readonly TenantContext $tenantContext) {}

    public function getTitle(): string
    {
        return __("dashboard.widgets.{$this->getId()}.title");
    }

    public function getConfigurableOptions(): array
    {
        return [];
    }

    public function validateSettings(array $settings): array
    {
        $options = $this->getConfigurableOptions();
        $errors = [];

        foreach ($settings as $name => $value) {
            $option = $options[$name] ?? null;

            if ($option === null) {
                $errors[(string) $name] = __('dashboard.errors.unknown_option', ['option' => $name]);
            } elseif ($option['type'] === 'enum' && ! in_array($value, $option['values'], true)) {
                $errors[(string) $name] = __('dashboard.errors.invalid_option_value', ['option' => $name, 'values' => implode(', ', $option['values'])]);
            } elseif ($option['type'] === 'integer' && (! is_int($value) || $value < $option['min'] || $value > $option['max'])) {
                $errors[(string) $name] = __('dashboard.errors.invalid_option_range', ['option' => $name, 'min' => $option['min'], 'max' => $option['max']]);
            }
        }

        return $errors;
    }

    /**
     * The given settings completed with the defaults of the configurable options; unknown keys are dropped.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function resolveSettings(array $settings): array
    {
        $resolved = [];

        foreach ($this->getConfigurableOptions() as $name => $option) {
            $value = $settings[$name] ?? $option['default'];
            $valid = $option['type'] === 'enum'
                ? in_array($value, $option['values'], true)
                : (is_int($value) && $value >= $option['min'] && $value <= $option['max']);

            $resolved[$name] = $valid ? $value : $option['default'];
        }

        return $resolved;
    }

    protected function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    protected function currency(): string
    {
        return $this->tenantContext->getTenant()?->default_currency ?? 'EUR';
    }

    /**
     * SQL expression turning a date column into a `YYYY-MM` bucket on any supported database.
     */
    protected function monthBucket(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
