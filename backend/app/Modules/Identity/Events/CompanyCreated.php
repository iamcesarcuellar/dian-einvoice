<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\Company;

/**
 * A company was created. Other modules listen to set up its defaults; listeners run inside the
 * same transaction, so a failure rolls the whole company back.
 */
final readonly class CompanyCreated
{
    public function __construct(public Company $company) {}
}
