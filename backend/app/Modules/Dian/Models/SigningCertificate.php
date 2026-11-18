<?php

namespace App\Modules\Dian\Models;

use App\Modules\Dian\Enums\CertificateStatus;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The certificate a company signs its documents with: one per company. Only public metadata is
 * kept here; the private key, the chain and the password stay in the key store.
 */
class SigningCertificate extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'key_reference',
        'subject',
        'issuer',
        'serial_number',
        'fingerprint_sha256',
        'valid_from',
        'valid_to',
        'is_test',
    ];

    /** @var list<string> */
    protected $hidden = ['key_reference'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /** The certificate of the current company, if it has one. */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function status(?Carbon $at = null): CertificateStatus
    {
        $at ??= Carbon::now();

        return match (true) {
            $at->lt($this->valid_from) => CertificateStatus::NotYetValid,
            $at->gt($this->valid_to) => CertificateStatus::Expired,
            $at->copy()->addDays(CertificateStatus::WARNING_DAYS)->gt($this->valid_to) => CertificateStatus::ExpiringSoon,
            default => CertificateStatus::Valid,
        };
    }
}
