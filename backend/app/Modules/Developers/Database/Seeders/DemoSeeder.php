<?php

namespace App\Modules\Developers\Database\Seeders;

use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * SYNTHETIC DEMO DATA: in Demo Comercial SAS, a read_write and a read_only API key and a webhook
 * subscription to the local webhook receiver with every event. It runs before the demo invoices,
 * so their events, and those of the notes and payments, leave deliveries in the receiver once the
 * relay and the workers start again after make seed. Only the hash of a key is stored, so the
 * read_write key is kept here for DatabaseSeeder to print once, at the end.
 */
final class DemoSeeder extends Seeder
{
    /** The plaintext of the synthetic read_write key, printed once by DatabaseSeeder. */
    public static ?string $readWriteKey = null;

    public function run(ApiKeys $keys, WebhookSubscriptions $subscriptions, LocalWebhookReceiver $receiver): void
    {
        $user = User::query()->where('email', 'demo@example.com')->firstOrFail();

        IdentityDemoSeeder::eachCompany(function (Company $company) use ($keys, $subscriptions, $receiver, $user): void {
            if ($company->identification_number !== IdentityDemoSeeder::NITS[0]) {
                return;
            }

            [, $plaintext] = $keys->create((string) __('developers::demo.keys.read_write'), ApiKeyLevel::ReadWrite, creator: $user);
            self::$readWriteKey = $plaintext;
            $keys->create((string) __('developers::demo.keys.read_only'), ApiKeyLevel::ReadOnly, creator: $user);

            $subscriptions->create([
                'url' => $receiver->urlFor($company->id),
                'description' => (string) __('developers::demo.subscription'),
                'events' => WebhookEventTypes::all(),
            ], $user);
        });
    }
}
