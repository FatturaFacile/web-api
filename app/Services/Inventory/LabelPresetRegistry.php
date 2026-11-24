<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\DTOs\Inventory\LabelSheetConfig;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Resolves label presets: the factory presets of `config/labels.php` first, then the custom presets stored by the
 * current tenant in `tenants.settings.preferences.labels.custom_presets`.
 */
class LabelPresetRegistry
{
    public const BARCODE_TYPES = ['ean13', 'code128', 'qrcode'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function systemPresets(): array
    {
        return config('labels.presets', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tenantPresets(): array
    {
        return $this->tenantContext->getTenant()?->settings['preferences']['labels']['custom_presets'] ?? [];
    }

    public function isSystemKey(string $key): bool
    {
        return array_key_exists($key, $this->systemPresets());
    }

    public function find(string $key): ?array
    {
        return $this->systemPresets()[$key] ?? $this->tenantPresets()[$key] ?? null;
    }

    public function defaultKey(): ?string
    {
        $key = $this->tenantContext->getTenant()?->settings['preferences']['labels']['default_preset'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Every preset available to the current tenant.
     *
     * @return array<int, array{key: string, name: string, system: bool, default: bool, geometry: array<string, float|int>}>
     */
    public function all(): array
    {
        $default = $this->defaultKey();
        $list = [];

        foreach ([[true, $this->systemPresets()], [false, $this->tenantPresets()]] as [$system, $presets]) {
            foreach ($presets as $key => $preset) {
                $list[] = [
                    'key' => (string) $key,
                    'name' => (string) ($preset['name'] ?? $key),
                    'system' => $system,
                    'default' => $key === $default,
                    'geometry' => $this->config($preset)->toArray(),
                ];
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    public function config(array $preset, int $startOffset = 0): LabelSheetConfig
    {
        return LabelSheetConfig::fromArray($preset, $startOffset);
    }

    /**
     * Validation rules for the label content options, under a key prefix (e.g. `content_options`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function contentOptionRules(string $prefix): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.show_name" => ['nullable', 'boolean'],
            "{$prefix}.show_sku" => ['nullable', 'boolean'],
            "{$prefix}.show_price" => ['nullable', 'boolean'],
            "{$prefix}.price_includes_tax" => ['nullable', 'boolean'],
            "{$prefix}.show_barcode" => ['nullable', 'boolean'],
            "{$prefix}.barcode_type" => ['nullable', Rule::in(self::BARCODE_TYPES)],
            "{$prefix}.font_size_pt" => ['nullable', 'numeric', 'between:4,48'],
        ];
    }

    /**
     * Validation rules for a full sheet geometry under a key prefix (e.g. `layout` or `custom_presets.*`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function geometryRules(string $prefix, string $presence = 'required'): array
    {
        return [
            "{$prefix}.page_width_mm" => [$presence, 'numeric', 'between:10,1000'],
            "{$prefix}.page_height_mm" => [$presence, 'numeric', 'between:10,1500'],
            "{$prefix}.margin_top_mm" => [$presence, 'numeric', 'between:0,100'],
            "{$prefix}.margin_right_mm" => [$presence, 'numeric', 'between:0,100'],
            "{$prefix}.margin_bottom_mm" => [$presence, 'numeric', 'between:0,100'],
            "{$prefix}.margin_left_mm" => [$presence, 'numeric', 'between:0,100'],
            "{$prefix}.rows" => [$presence, 'integer', 'between:1,100'],
            "{$prefix}.cols" => [$presence, 'integer', 'between:1,50'],
            "{$prefix}.label_width_mm" => [$presence, 'numeric', 'between:5,500'],
            "{$prefix}.label_height_mm" => [$presence, 'numeric', 'between:5,500'],
            "{$prefix}.gap_x_mm" => [$presence, 'numeric', 'between:0,50'],
            "{$prefix}.gap_y_mm" => [$presence, 'numeric', 'between:0,50'],
        ];
    }
}
