<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * Pins an account to a territory by hand.
 */
final class AssignAccountTerritoryRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['territory_id' => ['required', 'uuid', $this->ownRow('crm_territories')]];
    }
}
