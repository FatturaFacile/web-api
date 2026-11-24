<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workshop\Models\WorkshopFolder;

/**
 * Validates a workshop folder: vehicle, kind, currency and reception data.
 */
final class SaveFolderRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'vehicle_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('vehicles')],
            'contact_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')],
            'kind' => [$presence, Rule::in(WorkshopFolder::Kinds)],
            'received_at' => ['sometimes', 'nullable', 'date'],
            'promised_at' => ['sometimes', 'nullable', 'date'],
            'mileage_in' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'complaint' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => [$presence, 'string', 'size:3'],
        ];
    }
}
