<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record of the module as the API shows it: its visible attributes and the loaded relations. Secrets and file paths
 * are hidden by the models.
 */
final class AssociationRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
