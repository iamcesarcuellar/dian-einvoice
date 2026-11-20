<?php

namespace App\Support\Keys;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of the KMS key store: a PKCS#12 bundle and its password encrypted with a data key of their own
 * (AES-256-GCM), and that data key encrypted by KMS. Nothing here opens without KMS. Never serialized.
 *
 * @property string $kms_key_id
 * @property string $encrypted_data_key Base64.
 * @property string $nonce Base64.
 * @property string $tag Base64.
 * @property string $ciphertext Base64.
 */
class KmsKeyStoreEntry extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['id', 'company_id', 'kms_key_id', 'encrypted_data_key', 'nonce', 'tag', 'ciphertext'];

    /** @var list<string> */
    protected $hidden = ['encrypted_data_key', 'nonce', 'tag', 'ciphertext'];
}
