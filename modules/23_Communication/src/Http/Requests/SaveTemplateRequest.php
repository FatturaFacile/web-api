<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Services\TemplateRenderer;

/**
 * Validates a message template: a code, a channel and a language that are unique together, a single-line subject, and a
 * body whose braces are all well-formed placeholders (access is decided by module.access).
 */
final class SaveTemplateRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $renderer = app(TemplateRenderer::class);

            foreach (['subject', 'body'] as $field) {
                if (is_string($this->input($field)) && $renderer->hasStrayBraces($this->input($field))) {
                    $validator->errors()->add($field, 'Placeholders are written {{ name }}, with a lower-case name; no other braces are allowed.');
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bodyLimit = $this->input('channel') === 'email' ? 20000 : 1000;

        return [
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]{1,99}$/'],
            'channel' => ['required', Rule::in(RecipientRules::Channels), Rule::unique('communication_templates')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('code', $this->input('code'))->where('locale', $this->input('locale'))->ignore($this->route('id'))],
            'locale' => ['required', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'subject' => [$this->input('channel') === 'email' ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[^\r\n]*$/'],
            'body' => ['required', 'string', "max:{$bodyLimit}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
