<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A numbering range for one document type, such as a DIAN invoicing resolution. Consecutive
 * numbers are assigned from stage 3 on. The technical key is stored encrypted and never returned.
 */
class NumberTemplate extends Model
{
    use BelongsToCompany, HasUlids;

    /** Resolution validity uses Colombian calendar dates. */
    public const string TIMEZONE = 'America/Bogota';

    /** @var list<string> */
    protected $fillable = [
        'document_type',
        'prefix',
        'from_number',
        'to_number',
        'next_number',
        'resolution_number',
        'resolution_date',
        'valid_from',
        'valid_to',
        'technical_key',
        'is_default',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['technical_key'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => NumberingType::class,
            'from_number' => 'integer',
            'to_number' => 'integer',
            'next_number' => 'integer',
            'resolution_date' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'technical_key' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function status(): NumberingStatus
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();

        return match (true) {
            $this->next_number > $this->to_number => NumberingStatus::Exhausted,
            $this->valid_to !== null && $today > $this->valid_to->toDateString() => NumberingStatus::Expired,
            $this->valid_from !== null && $today < $this->valid_from->toDateString() => NumberingStatus::Upcoming,
            default => NumberingStatus::Active,
        };
    }

    /** Numbers already assigned. */
    public function usedCount(): int
    {
        return max(0, min($this->next_number, $this->to_number + 1) - $this->from_number);
    }

    /** Size of the range. */
    public function totalCount(): int
    {
        return $this->to_number - $this->from_number + 1;
    }
}
