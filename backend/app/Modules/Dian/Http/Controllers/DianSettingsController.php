<?php

namespace App\Modules\Dian\Http\Controllers;

use App\Modules\Dian\Http\Requests\DianSettingsRequest;
use App\Modules\Dian\Http\Resources\DianSettingsResource;
use App\Modules\Dian\Models\DianSetting;

/**
 * DIAN settings of the current company: environment, software ID, PIN and test set ID.
 * Owners and admins only.
 */
final class DianSettingsController
{
    /**
     * The settings; a company that never saved them gets the empty defaults.
     */
    public function show(): DianSettingsResource
    {
        return DianSettingsResource::make(DianSetting::current());
    }

    /**
     * Partial update. The PIN is stored encrypted and never returned.
     */
    public function update(DianSettingsRequest $request): DianSettingsResource
    {
        $settings = DianSetting::current();
        $settings->fill($request->settings())->save();

        return DianSettingsResource::make($settings->refresh());
    }
}
