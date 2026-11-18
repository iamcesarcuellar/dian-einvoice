<?php

namespace App\Modules\Reference\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Colombian department (DANE DIVIPOLA). Other tables reference it by its two-digit code.
 */
class Department extends Model
{
    use HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['code', 'name'];
}
