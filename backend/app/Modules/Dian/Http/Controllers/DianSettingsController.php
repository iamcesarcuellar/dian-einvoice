<?php

namespace App\Modules\Dian\Http\Controllers;

use App\Modules\Dian\Http\Requests\DianSettingsRequest;
use App\Modules\Dian\Http\Resources\DianSettingsResource;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * DIAN settings of the current company: environment, software ID, PIN and test set ID.
 * Owners and admins only. Saving registers the company again with the local DIAN simulator.
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
    public function update(DianSettingsRequest $request, Outbox $outbox, CurrentCompany $currentCompany): DianSettingsResource
    {
        $settings = DianSetting::current();

        DB::transaction(function () use ($settings, $request, $outbox, $currentCompany): void {
            $settings->fill($request->settings())->save();
            $outbox->record($currentCompany->id(), 'company', $currentCompany->id(), ChainEvents::TAXPAYER_CHANGED);
        });

        return DianSettingsResource::make($settings->refresh());
    }
}
