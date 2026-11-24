<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\MarketingAutomation\Domain\Enums\AttributionModel;
use Modules\MarketingAutomation\Domain\Enums\CampaignChannel;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Domain\Enums\TriggerType;

final class SaveCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role access to the module is enforced by the module.access middleware of the route group.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $partial = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => [$partial, 'string', 'max:160'],
            'channel' => [$partial, Rule::in(CampaignChannel::values())],
            'trigger_type' => [$partial, Rule::in(TriggerType::values())],
            'segment_id' => ['nullable', 'uuid', Rule::exists('ma_segments', 'id')->where('tenant_id', $tenantId)],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'email', 'max:190'],
            'sender_postal_address' => ['nullable', 'string', 'max:255'],
            'provider' => ['sometimes', Rule::in(MailProvider::values())],
            'template_mjml_json' => ['sometimes', 'nullable', 'array'],
            'template_mjml_json.blocks' => ['sometimes', 'array'],
            'template_mjml_json.blocks.*.type' => ['required_with:template_mjml_json.blocks', 'string', 'in:heading,text,image,button,divider,spacer,product,html'],
            'ab_test_split_variant_json' => ['sometimes', 'nullable', 'array'],
            'ab_test_split_variant_json.variants' => ['sometimes', 'array', 'max:4'],
            'ab_test_split_variant_json.variants.*.key' => ['required_with:ab_test_split_variant_json.variants', 'string', 'max:20'],
            'ab_test_split_variant_json.variants.*.percent' => ['required_with:ab_test_split_variant_json.variants', 'integer', 'min:1', 'max:100'],
            'ab_test_split_variant_json.winner_criteria' => ['sometimes', 'in:open_rate,click_rate,revenue'],
            'utm_campaign_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'attribution_model' => ['sometimes', Rule::in(AttributionModel::values())],
            'attribution_window_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['CHF', 'EUR'])],
            'campaign_cost' => ['sometimes', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:60'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The variants of an A/B test must add up to 100 %.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $variants = (array) $this->input('ab_test_split_variant_json.variants', []);
            if ($variants !== [] && array_sum(array_map(static fn ($v): int => (int) ($v['percent'] ?? 0), $variants)) !== 100) {
                $validator->errors()->add('ab_test_split_variant_json.variants', 'split_must_total_100');
            }
        }];
    }
}
