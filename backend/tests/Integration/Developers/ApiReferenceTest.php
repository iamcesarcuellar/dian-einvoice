<?php

namespace Tests\Integration\Developers;

use Illuminate\Support\Facades\Artisan;
use Tests\IntegrationTestCase;

/**
 * The API reference (Scramble, /docs/api) reads every validation rule of the code: its analysis reports no warning, so
 * the reference documents each rule and shows no warning badge.
 */
final class ApiReferenceTest extends IntegrationTestCase
{
    public function test_the_analysis_of_the_api_reference_reports_no_warning(): void
    {
        $this->assertSame(0, Artisan::call('scramble:analyze'));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/producing [1-9][0-9]* OpenAPI operations/', $output);
        $this->assertStringNotContainsString('WARN', $output);
    }
}
