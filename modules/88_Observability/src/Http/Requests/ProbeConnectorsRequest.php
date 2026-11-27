<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Observability\Connectors\ConnectorCatalog;

final class ProbeConnectorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['key' => ['sometimes', 'nullable', 'string', Rule::in(app(ConnectorCatalog::class)->keys())]];
    }
}
