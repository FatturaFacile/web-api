<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Modules\Associations\Http\Requests\UpdateSettingsRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Support\AssociationCapability;

final class SettingsController extends AssociationController
{
    #[AssociationGet('/api/v1/associations/settings', 'showAssociationSettings', 'Show the settings of the module')]
    public function show(): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Read);

        return new AssociationRecordResource(AssociationSetting::current());
    }

    #[AssociationPut('/api/v1/associations/settings', 'updateAssociationSettings', 'Update the settings of the module', 'AssociationSettingsRequest')]
    public function update(UpdateSettingsRequest $request): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        $settings = AssociationSetting::current();
        $settings->update($request->validated());
        $settings->wasRecentlyCreated = false;

        return new AssociationRecordResource($settings);
    }
}
