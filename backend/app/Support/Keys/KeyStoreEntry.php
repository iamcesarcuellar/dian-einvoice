<?php

namespace App\Support\Keys;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of the local key store. The payload (PKCS#12 bundle and password) is encrypted with the
 * app key and never serialized.
 */
class KeyStoreEntry extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['company_id', 'payload'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
        ];
    }
}
