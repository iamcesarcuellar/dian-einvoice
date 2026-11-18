<?php

namespace App\Modules\Reference\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Colombian municipality (DANE DIVIPOLA). Other tables reference it by its five-digit code.
 */
class Municipality extends Model
{
    use HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['code', 'department_code', 'name'];

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }
}
