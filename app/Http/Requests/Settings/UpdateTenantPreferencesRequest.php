<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Inventory\LabelPresetRegistry;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTenantPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules depend on the preference group in the URL; unknown groups have no rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return app(TenantPreferences::class)->rules((string) $this->route('group'));
    }

    /**
     * Cross-field validation for the label preferences: preset keys, grid geometry and the default preset.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->route('group') !== 'labels' || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $registry = app(LabelPresetRegistry::class);
                $custom = $this->has('custom_presets') ? (array) $this->input('custom_presets', []) : $registry->tenantPresets();

                foreach ((array) $this->input('custom_presets', []) as $key => $preset) {
                    if (! preg_match('/^[a-z0-9_]{1,50}$/', (string) $key)) {
                        $validator->errors()->add("custom_presets.{$key}", __('labels.errors.preset_key_invalid', ['key' => $key]));
                    } elseif ($registry->isSystemKey((string) $key)) {
                        $validator->errors()->add("custom_presets.{$key}", __('labels.errors.preset_key_reserved', ['key' => $key]));
                    } elseif (! $registry->config($preset)->fits()) {
                        $validator->errors()->add("custom_presets.{$key}", __('labels.errors.layout_does_not_fit'));
                    }
                }

                $default = $this->input('default_preset');

                if (is_string($default) && $default !== '' && ! $registry->isSystemKey($default) && ! array_key_exists($default, $custom)) {
                    $validator->errors()->add('default_preset', __('labels.errors.unknown_preset', ['preset' => $default]));
                }
            },
        ];
    }
}
