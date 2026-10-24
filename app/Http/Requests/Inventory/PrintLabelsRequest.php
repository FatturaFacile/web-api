<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\DTOs\Inventory\LabelSheetConfig;
use App\Services\Inventory\LabelPresetRegistry;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

class PrintLabelsRequest extends FormRequest
{
    private ?LabelSheetConfig $config = null;

    /**
     * Content defaults carried by the resolved preset.
     *
     * @var array<string, mixed>
     */
    private array $presetDefaults = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return array_merge(
            [
                'layout_mode' => ['nullable', Rule::in(['preset', 'custom'])],
                'preset' => ['nullable', 'string', 'max:50'],
                'layout' => ['required_if:layout_mode,custom', 'nullable', 'array'],
                'start_offset' => ['nullable', 'integer', 'min:0'],
                'items' => ['required', 'array', 'min:1', 'max:200'],
                'items.*.product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('labels.max_quantity_per_item')],
            ],
            LabelPresetRegistry::geometryRules('layout', 'required_if:layout_mode,custom'),
            LabelPresetRegistry::contentOptionRules('content_options'),
        );
    }

    /**
     * Resolve the sheet geometry once the payload is structurally valid: a preset key (system presets first, then the
     * tenant's custom presets, falling back to the tenant's default preset) or an explicit custom layout.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $registry = app(LabelPresetRegistry::class);
                $offset = (int) $this->input('start_offset', 0);

                if (($this->input('layout_mode') ?? 'preset') === 'custom') {
                    $geometry = (array) $this->input('layout');
                    $errorKey = 'layout';
                } else {
                    $key = (string) ($this->input('preset') ?: $registry->defaultKey());
                    $errorKey = 'preset';

                    if ($key === '') {
                        $validator->errors()->add('preset', __('labels.errors.preset_required'));

                        return;
                    }

                    $geometry = $registry->find($key);

                    if ($geometry === null) {
                        $validator->errors()->add('preset', __('labels.errors.unknown_preset', ['preset' => $key]));

                        return;
                    }

                    $this->presetDefaults = (array) ($geometry['defaults'] ?? []);
                }

                $config = $registry->config($geometry, $offset);

                if (! $config->fits()) {
                    $validator->errors()->add($errorKey, __('labels.errors.layout_does_not_fit'));
                }

                if ($offset >= $config->labelsPerPage()) {
                    $validator->errors()->add('start_offset', __('labels.errors.offset_too_large', ['count' => $config->labelsPerPage()]));
                }

                $max = (int) config('labels.max_labels_per_request');

                if (array_sum(array_column((array) $this->input('items'), 'quantity')) > $max) {
                    $validator->errors()->add('items', __('labels.errors.too_many_labels', ['max' => $max]));
                }

                $this->config = $config;
            },
        ];
    }

    /**
     * The validated sheet geometry including the start offset.
     */
    public function layoutConfig(): LabelSheetConfig
    {
        return $this->config ?? throw new LogicException('The label layout is only available after validation.');
    }

    /**
     * Content options with precedence: request, then preset defaults, then the global defaults.
     *
     * @return array<string, mixed>
     */
    public function contentOptions(): array
    {
        $requested = array_filter((array) $this->input('content_options', []), fn (mixed $value): bool => $value !== null);

        return array_merge(config('labels.defaults'), $this->presetDefaults, $requested);
    }

    /**
     * @return array<int, array{product_id: string, quantity: int}>
     */
    public function labelItems(): array
    {
        return array_map(
            fn (array $item): array => ['product_id' => (string) $item['product_id'], 'quantity' => (int) $item['quantity']],
            array_values((array) $this->input('items'))
        );
    }
}
