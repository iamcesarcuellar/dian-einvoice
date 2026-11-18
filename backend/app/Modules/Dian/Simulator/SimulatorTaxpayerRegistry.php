<?php

namespace App\Modules\Dian\Simulator;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Contracts\Cache\Repository;

/**
 * Registers companies with the local DIAN simulator through its admin API. Settings and numbering
 * changes register again (event dian.taxpayer_changed); before each transmission the data is
 * compared with what was last registered, through a short-lived fingerprint in the cache, so a
 * simulator that lost its state learns it again within a minute.
 */
final class SimulatorTaxpayerRegistry implements TaxpayerRegistry
{
    private const int CACHE_SECONDS = 60;

    public function __construct(
        private readonly DianSimulator $simulator,
        private readonly Repository $cache,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function ensureRegistered(Company $company): void
    {
        $registration = $this->registration($company);
        $fingerprint = hash('sha256', (string) json_encode($registration));

        if ($this->cache->get($this->key($company)) === $fingerprint) {
            return;
        }

        $this->simulator->registerTaxpayer($company->identification_number, $registration);
        $this->cache->put($this->key($company), $fingerprint, self::CACHE_SECONDS);
    }

    public function register(Company $company): void
    {
        $registration = $this->registration($company);

        $this->simulator->registerTaxpayer($company->identification_number, $registration);
        $this->cache->put($this->key($company), hash('sha256', (string) json_encode($registration)), self::CACHE_SECONDS);
    }

    /**
     * NIT, legal name, software and invoice ranges. The PIN and the technical keys are decrypted
     * here: the simulator needs them to check the software security code and the CUFE.
     *
     * @return array<string, mixed>
     */
    private function registration(Company $company): array
    {
        return $this->currentCompany->runAs($company, function () use ($company): array {
            $settings = DianSetting::current();

            $ranges = NumberTemplate::query()
                ->where('document_type', DocumentType::Invoice)
                ->whereNotNull('resolution_number')
                ->orderBy('id')
                ->get()
                ->filter(fn (NumberTemplate $template): bool => filled($template->technical_key))
                ->map(fn (NumberTemplate $template): array => [
                    'resolution_number' => $template->resolution_number,
                    'prefix' => $template->prefix ?? '',
                    'from' => $template->from_number,
                    'to' => $template->to_number,
                    'valid_from' => $template->valid_from?->toDateString(),
                    'valid_to' => $template->valid_to?->toDateString(),
                    'technical_key' => $template->technical_key,
                ])
                ->values()
                ->all();

            return [
                'nit' => $company->identification_number,
                'check_digit' => (string) $company->check_digit,
                'legal_name' => $company->legal_name,
                'software' => filled($settings->software_id) && filled($settings->software_pin)
                    ? ['id' => $settings->software_id, 'pin' => $settings->software_pin]
                    : null,
                'ranges' => $ranges,
            ];
        });
    }

    private function key(Company $company): string
    {
        return 'dian-simulator:taxpayer:'.$company->getKey();
    }
}
