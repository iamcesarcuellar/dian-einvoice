<?php

namespace App\Modules\Dian\Models;

use App\Modules\Dian\Enums\DianEnvironment;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * DIAN settings of a company: one row per company. The software PIN is stored encrypted and
 * never returned; with the software ID it yields the software security code of each document.
 */
class DianSetting extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['environment', 'software_id', 'software_pin', 'test_set_id'];

    /** @var list<string> */
    protected $hidden = ['software_pin'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'environment' => DianEnvironment::Test->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => DianEnvironment::class,
            'software_pin' => 'encrypted',
        ];
    }

    /** The settings of the current company, or an unsaved empty row. */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }
}
