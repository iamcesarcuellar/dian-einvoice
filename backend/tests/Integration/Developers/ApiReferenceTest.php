<?php

namespace Tests\Integration\Developers;

use App\Support\ApiDocs\ApiDocumentation;
use App\Support\ApiDocs\ReferenceCatalog;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\IntegrationTestCase;

/**
 * The API reference (Scramble, /docs/api) and the same reference in Spanish (/docs/api/es). The analysis of each reads
 * every validation rule of the code and reports no warning, so both document each rule and show no warning badge.
 * openapi.json and openapi.es.json, at the root of the repository, are what the code generates: what scramble:export
 * writes in a process of its own, as make openapi runs it (Scramble keeps state between two documents built in one
 * process). Building the Spanish reference leaves no Spanish in the English one. Each reference links to the other on
 * the first line of its description, and the Spanish page is in Spanish. e2e/docs/reference.mjs (make docs-check)
 * holds the texts of the Spanish reference to the English ones.
 *
 * A test that builds a reference runs in a process of its own: building one leaves Scramble's state in the process,
 * which would make the test runner heavier for every test after it, the concurrency tests that fork it included.
 */
final class ApiReferenceTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function references(): iterable
    {
        yield 'English' => [Scramble::DEFAULT_API, 'openapi.json'];
        yield 'Spanish' => [ApiDocumentation::SPANISH, 'openapi.es.json'];
    }

    #[DataProvider('references')]
    #[RunInSeparateProcess]
    public function test_the_analysis_of_the_api_reference_reports_no_warning(string $api, string $file): void
    {
        $this->assertSame(0, Artisan::call('scramble:analyze', ['--api' => $api]));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/producing [1-9][0-9]* OpenAPI operations/', $output);
        $this->assertStringNotContainsString('WARN', $output);
    }

    #[DataProvider('references')]
    public function test_the_exported_reference_is_what_the_code_generates(string $api, string $file): void
    {
        $directory = getenv('OPENAPI_DIR');

        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('OPENAPI_DIR is not set: make test-backend mounts the repository and sets it.');
        }

        $this->assertFileExists("{$directory}/{$file}", "Run make openapi to write {$file}.");
        $export = sys_get_temp_dir().'/'.uniqid('reference-', true).'.json';

        try {
            Process::path(base_path())->timeout(120)->run(['php', 'artisan', 'scramble:export', '--api='.$api, '--path='.$export])->throw();
            $this->assertSame(file_get_contents($export), file_get_contents("{$directory}/{$file}"), "{$file} is not what the code generates: run make openapi.");
        } finally {
            @unlink($export);
        }
    }

    #[RunInSeparateProcess]
    public function test_building_the_spanish_reference_leaves_no_spanish_in_the_english_one(): void
    {
        $spanish = self::texts(ApiDocumentation::SPANISH);
        $english = self::texts(Scramble::DEFAULT_API);
        $catalog = json_decode((string) file_get_contents(resource_path('api-reference/es.json')), true, flags: JSON_THROW_ON_ERROR);
        $translations = is_array($catalog) && is_array($catalog['translations'] ?? null) ? array_values(array_filter($catalog['translations'], is_string(...))) : [];

        $this->assertNotSame($english, $spanish);
        $this->assertSame([], array_values(array_filter($english, fn (string $text): bool => in_array($text, $translations, true))), 'Texts of the Spanish reference in the English one.');
    }

    #[RunInSeparateProcess]
    public function test_each_reference_links_to_the_other_and_the_spanish_page_is_in_spanish(): void
    {
        $english = $this->getJson('/docs/api.json')->assertOk()->json('info.description');
        $spanish = $this->getJson('/docs/api/es.json')->assertOk()->json('info.description');
        $this->assertIsString($english);
        $this->assertIsString($spanish);
        $this->assertStringStartsWith('**English** · [Español]('.route('scramble.docs.es.ui').")\n\n", $english);
        $this->assertStringStartsWith('[English]('.route('scramble.docs.ui').") · **Español**\n\n", $spanish);

        $title = ReferenceCatalog::spanish()->pageTitle('(none)');
        $this->assertNotSame('(none)', $title, 'The catalog gives the Spanish page its title (page.title).');

        $page = (string) $this->get('/docs/api/es')->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="es"', $page);
        $this->assertStringContainsString('<title>'.e($title).'</title>', $page);
        $this->assertStringContainsString('<html lang="en"', (string) $this->get('/docs/api')->assertOk()->getContent());
    }

    /**
     * The summaries and descriptions of a reference, built in this process.
     *
     * @return list<string>
     */
    private static function texts(string $api): array
    {
        $config = Scramble::getGeneratorConfig($api);
        $document = app(Generator::class)->generate($config)->spec($config->openApiVersion());
        $texts = [];
        array_walk_recursive($document, function (mixed $value, int|string $key) use (&$texts): void {
            if (($key === 'description' || $key === 'summary') && is_string($value)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }
}
