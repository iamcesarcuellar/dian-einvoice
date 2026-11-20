<?php

namespace Database\Seeders;

use App\Modules\Billing\Database\Seeders\DemoSeeder as BillingDemoSeeder;
use App\Modules\Catalog\Database\Seeders\DemoSeeder as CatalogDemoSeeder;
use App\Modules\Delivery\LocalMailbox\LocalMailbox;
use App\Modules\Delivery\LocalMailbox\LocalMailboxUnavailable;
use App\Modules\Developers\Database\Seeders\DemoSeeder as DevelopersDemoSeeder;
use App\Modules\Dian\Database\Seeders\DemoSeeder as DianDemoSeeder;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Support\Cloud\EmulatedCloud;
use App\Support\Console\LocalOnly;
use App\Support\Storage\DocumentStorage;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class DatabaseSeeder extends Seeder
{
    /**
     * Synthetic demo data for local development only. DIAN settings and test certificates come
     * before the invoices: issuing needs them, and every issued demo invoice goes through the real
     * electronic document chain against the local DIAN simulator, and once accepted through the
     * customer delivery, whose emails land in the local mailbox (in the emulated cloud, in SES). The
     * demo API keys and the webhook subscription to the local receiver come before them too, so their
     * events reach the receiver.
     */
    public function run(DocumentStorage $storage, DianSimulator $simulator, LocalMailbox $mailbox, EmulatedCloud $cloud): void
    {
        // Synthetic users with a known password and test certificates: never outside local development.
        LocalOnly::ensure('The demo seeder');

        // make seed has just recreated the database, so every stored file is an orphan. The guard
        // keeps the files of a database that still has documents.
        if (DB::table('documents')->doesntExist()) {
            $storage->purge();

            // A fresh world: the simulator forgets the taxpayers, documents and modes of the previous
            // database, and jobs queued for it are dropped.
            $simulator->reset();

            // The local mailbox forgets the emails of the previous database too; in the emulated cloud,
            // the list of the emails SES took.
            if ($cloud->enabled()) {
                try {
                    $cloud->clearSentEmails();
                } catch (Throwable $exception) {
                    $this->command?->warn('The local AWS emulator did not forget the emails SES took: '.$exception->getMessage());
                }
            } else {
                try {
                    $mailbox->clear();
                } catch (LocalMailboxUnavailable $exception) {
                    $this->command?->warn('The local mailbox is not answering, so it keeps its earlier emails: '.$exception->getMessage());
                }
            }

            $queue = Queue::connection();

            if ($queue instanceof ClearableQueue) {
                $queue->clear(config()->string('queue.connections.'.config()->string('queue.default').'.queue', 'default'));
            }
        }

        $this->call([
            IdentityDemoSeeder::class,
            CatalogDemoSeeder::class,
            DianDemoSeeder::class,
            DevelopersDemoSeeder::class,
            BillingDemoSeeder::class,
        ]);

        // Only the hash of an API key is stored: this is the one chance to show the demo key.
        if (DevelopersDemoSeeder::$readWriteKey !== null) {
            $this->command?->newLine();
            $this->command?->warn('SYNTHETIC demo API key of Demo Comercial SAS (read_write), shown only now; only its hash is stored:');
            $this->command?->line('  '.DevelopersDemoSeeder::$readWriteKey);
            $this->command?->newLine();
        }
    }
}
