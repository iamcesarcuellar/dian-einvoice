<?php

namespace Tests\Integration\Operations;

use Illuminate\Support\Facades\Artisan;
use Tests\IntegrationTestCase;

/**
 * Every command of the application the runbooks (docs/runbooks) and the deployment guide cite, in English and in
 * their Spanish translations (docs/es), is a command of artisan: those an operator runs in the emulated cloud (make
 * cloud-artisan ARGS="...") and those of a real account (the payload of aws lambda invoke of the console function or of
 * the relay). make test-backend mounts the documentation read-only (RUNBOOKS_DIR); the make targets and the AWS CLI
 * they cite are checked by infra/functions/test/runbooks.test.mjs.
 */
final class RunbooksTest extends IntegrationTestCase
{
    public function test_every_command_of_the_application_the_runbooks_cite_exists(): void
    {
        $directory = getenv('RUNBOOKS_DIR');

        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('RUNBOOKS_DIR is not set: make test-backend mounts docs/runbooks and sets it.');
        }

        $docs = dirname($directory);
        $pages = [...(glob($directory.'/*.md') ?: []), $docs.'/deployment.md'];
        $translations = [...(glob($docs.'/es/runbooks/*.md') ?: []), ...(is_file($docs.'/es/deployment.md') ? [$docs.'/es/deployment.md'] : [])];
        $this->assertGreaterThan(10, count($pages), "{$directory} holds the runbooks");
        $this->assertSame(count($pages), count($translations), 'Each runbook and the deployment guide have their Spanish translation in docs/es.');
        $pages = [...$pages, ...$translations];
        $commands = [];

        foreach ($pages as $page) {
            $text = (string) file_get_contents($page);
            preg_match_all('/ARGS="([a-z][a-z0-9:-]*)[^"]*"|--payload \'"([a-z][a-z0-9:-]*)[^"]*"\'/', $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

            foreach ($matches as $match) {
                $commands[$match[1] ?? $match[2] ?? ''][] = str_replace($docs.'/', '', $page);
            }
        }

        $known = array_keys(Artisan::all());
        $missing = array_diff(array_keys($commands), $known);

        $this->assertContains('chain:status', array_keys($commands), 'The runbooks cite the commands of the application.');
        $this->assertSame([], array_values($missing), 'Commands the runbooks cite that the application does not have: '.implode(', ', array_map(fn (string $command): string => $command.' ('.implode(', ', array_unique($commands[$command])).')', $missing)));
    }
}
