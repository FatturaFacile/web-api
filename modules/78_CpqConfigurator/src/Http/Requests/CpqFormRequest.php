<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared by the requests of the CPQ configurator: access is decided by the module.access middleware and the policy;
 * texts are {it, en, de, fr} maps (Italian is the reference language, the others are optional).
 */
abstract class CpqFormRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules of a localised text: the map and its four languages.
     *
     * @return array<string, list<string>>
     */
    protected function i18n(string $field, bool $required, int $max = 190): array
    {
        $presence = $required ? ($this->isMethod('POST') ? 'required' : 'sometimes') : 'nullable';

        return [
            $field => [$presence, 'array'],
            "{$field}.it" => [$required ? 'required_with:'.$field : 'nullable', 'string', "max:{$max}"],
            "{$field}.en" => ['nullable', 'string', "max:{$max}"],
            "{$field}.de" => ['nullable', 'string', "max:{$max}"],
            "{$field}.fr" => ['nullable', 'string', "max:{$max}"],
        ];
    }

    /**
     * Code unique among the not deleted rows of the model (and the tenant), except the row being changed.
     *
     * @param  class-string<Model>  $class
     */
    protected function uniqueInModel(string $class, ?string $modelId): Unique
    {
        /** @var Model $instance */
        $instance = new $class;

        return Rule::unique($instance->getTable(), 'code')
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->where('model_id', $modelId)
            ->whereNull('deleted_at')
            ->ignore($this->isMethod('POST') ? null : $this->route('id'));
    }

    /**
     * The model the child belongs to: the route id on creation, the model of the row on a change.
     *
     * @param  class-string<Model>  $class
     */
    protected function modelIdOf(string $class): ?string
    {
        if ($this->isMethod('POST')) {
            return (string) $this->route('id');
        }

        return $class::query()->find($this->route('id'))?->getAttribute('model_id');
    }

    protected function presence(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }
}
