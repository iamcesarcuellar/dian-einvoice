<?php

namespace App\Modules\Developers\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An event published to the webhook subscriptions of a company. payload is the JSON body, kept byte
 * for byte, so every subscription and every attempt sends exactly the same signed content.
 */
class WebhookEvent extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    /** Public ids carry a prefix: evt_ and the ULID. */
    public const string ID_PREFIX = 'evt_';

    /** @var list<string> */
    protected $fillable = ['id', 'type', 'source_event_id', 'payload', 'created_at'];

    /** The id integrations see, and the webhook-id header of every attempt. */
    public function publicId(): string
    {
        return self::ID_PREFIX.$this->getKey();
    }
}
