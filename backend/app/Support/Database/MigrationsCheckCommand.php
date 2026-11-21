<?php

namespace App\Support\Database;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * make migrations-check, a step of the deployment pipeline and a test of make test (MigrationSafetyTest): every
 * migration the application loads is safe to run before the functions take the new code, so a statement in up() that
 * drops or renames a column or a table names the release from which it is safe (MigrationSafety).
 */
final class MigrationsCheckCommand extends Command
{
    protected $signature = 'migrations:check';

    protected $description = 'Fail when a migration drops or renames a column or a table without naming the release from which that step is safe';

    public function handle(Migrator $migrator, MigrationSafety $safety): int
    {
        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $findings = 0;

        foreach ($files as $file) {
            foreach ($safety->findings($file) as $finding) {
                $findings++;
                $this->error(sprintf('%s:%d drops or renames without "// migration-safety: safe since vX.Y.Z: <why>" above it: %s', str_replace(base_path().'/', '', $file), $finding['line'], $finding['statement']));
            }
        }

        if ($findings > 0) {
            $this->line('The release before runs on the migrated schema while the functions deploy: take a column or a table away only once no release that reads it runs (docs/deployment.md).');

            return self::FAILURE;
        }

        $this->line(sprintf('The %d migrations only add to the schema, or name the release from which what they take away is safe.', count($files)));

        return self::SUCCESS;
    }
}
