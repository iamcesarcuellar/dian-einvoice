<?php

namespace Database\Seeders;

use App\Support\Language;

/**
 * The synthetic demo data in Spanish: make demo LOCALE=es, make seed LOCALE=es and make cloud-demo LOCALE=es run
 * php artisan migrate:fresh --seed --seeder=SpanishDemoSeeder. The same documents, amounts and dates as
 * DatabaseSeeder, with every text in Spanish and both demo companies issuing their documents in Spanish.
 */
final class SpanishDemoSeeder extends DatabaseSeeder
{
    protected function language(): Language
    {
        return Language::Spanish;
    }
}
