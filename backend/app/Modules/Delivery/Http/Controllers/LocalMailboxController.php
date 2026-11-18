<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\Delivery\LocalMailbox\LocalMailbox;
use Illuminate\Http\JsonResponse;

/**
 * The local development mailbox, a development tool: the address of its web interface and whether
 * it answers. Every email of this project lands there; none leaves the machine. Owners and admins.
 */
final class LocalMailboxController
{
    public function __invoke(LocalMailbox $mailbox): JsonResponse
    {
        return response()->json(['data' => [
            'url' => $mailbox->url(),
            ...$mailbox->status(),
        ]]);
    }
}
