<?php

namespace App\Modules\Identity\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query to the rows of the current company. It fails closed: without a current
 * company the query throws instead of returning rows from every tenant.
 *
 * @implements Scope<Model>
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('company_id'), app(CurrentCompany::class)->id());
    }
}
