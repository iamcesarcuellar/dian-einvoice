<?php

namespace App\Modules\Dian\Simulator;

use App\Modules\Identity\Models\Company;

/**
 * Makes the DIAN side know a company: its NIT, its software with the PIN and its invoice numbering
 * ranges with their technical keys. With the real DIAN that is done once, by hand, in its portal;
 * the local simulator learns it from this registry, so nobody has to register anything by hand.
 */
interface TaxpayerRegistry
{
    /** Registers the company unless the simulator already has exactly this data. */
    public function ensureRegistered(Company $company): void;

    /** Registers the company again, such as after its settings or numbering changed. */
    public function register(Company $company): void;
}
