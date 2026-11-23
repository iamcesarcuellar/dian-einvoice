<?php

namespace App\Modules\Dian;

use App\Modules\Dian\Console\ChainStatusCommand;
use App\Modules\Dian\Console\DianChaosCommand;
use App\Modules\Dian\Console\DianCheckCommand;
use App\Modules\Dian\Console\DianProbeCommand;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Modules\Dian\Pipeline\GenerateXml;
use App\Modules\Dian\Pipeline\RecordDianResponse;
use App\Modules\Dian\Pipeline\RegisterTaxpayer;
use App\Modules\Dian\Pipeline\SignXml;
use App\Modules\Dian\Pipeline\StalledChainSweeper;
use App\Modules\Dian\Pipeline\TransmitDocument;
use App\Modules\Dian\Retention\PruneDianTransmissions;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Dian\Simulator\SimulatorTaxpayerRegistry;
use App\Modules\Dian\Simulator\TaxpayerRegistry;
use App\Modules\Dian\Transmission\DianGateway;
use App\Modules\Dian\Transmission\SoapDianGateway;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\Sweeper;
use App\Support\Retention\Pruner;
use Illuminate\Support\ServiceProvider;

/**
 * Dian module: DIAN settings of each company, the CUFE and software security code, the signed UBL
 * 2.1 XML, and the asynchronous chain that generates, signs and transmits it and records the
 * answer. The DIAN side is always the local simulator (compose service simulator).
 */
final class DianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DianGateway::class, fn (): DianGateway => new SoapDianGateway(
            (string) config('dian.soap_url'),
            (float) config('dian.connect_timeout'),
            (float) config('dian.timeout'),
        ));

        $this->app->singleton(DianSimulator::class, fn (): DianSimulator => new DianSimulator(
            (string) config('dian.simulator.url'),
            (string) config('dian.simulator.token'),
            (float) config('dian.simulator.timeout'),
        ));

        // Resolved per use: it acts on the current company of the request or job.
        $this->app->bind(TaxpayerRegistry::class, SimulatorTaxpayerRegistry::class);

        $this->app->tag([StalledChainSweeper::class], Sweeper::class);
        $this->app->tag([PruneDianTransmissions::class], Pruner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'dian');

        // Each event of the chain starts its next step; the relay publishes them to the queue.
        $subscribers = $this->app->make(OutboxSubscribers::class);
        $subscribers->subscribe(ChainEvents::ISSUED, GenerateXml::class);
        $subscribers->subscribe(ChainEvents::CREDIT_NOTE_ISSUED, GenerateXml::class);
        $subscribers->subscribe(ChainEvents::DEBIT_NOTE_ISSUED, GenerateXml::class);
        $subscribers->subscribe(ChainEvents::GENERATE, GenerateXml::class);
        $subscribers->subscribe(ChainEvents::SIGN, SignXml::class);
        $subscribers->subscribe(ChainEvents::TRANSMIT, TransmitDocument::class);
        $subscribers->subscribe(ChainEvents::RESPONSE_RECEIVED, RecordDianResponse::class);
        $subscribers->subscribe(ChainEvents::TAXPAYER_CHANGED, RegisterTaxpayer::class);

        if ($this->app->runningInConsole()) {
            $this->commands([DianCheckCommand::class, DianChaosCommand::class, ChainStatusCommand::class, DianProbeCommand::class]);
        }
    }
}
