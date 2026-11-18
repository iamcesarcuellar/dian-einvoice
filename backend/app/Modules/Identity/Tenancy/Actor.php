<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Who acts on a request, for the services that record it: the signed-in user, or null when the
 * request signs with an API key (the timelines then name the key, see CurrentCompany::apiKey()).
 */
final class Actor
{
    public static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
