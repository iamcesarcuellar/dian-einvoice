<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Identity\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For business models that belong to a company. Queries are limited to the
 * current company and new records are assigned to it. Console code (seeders, jobs) must set a
 * current company first or opt out explicitly with withoutGlobalScope(CompanyScope::class).
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(CurrentCompany::class)->id());
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
