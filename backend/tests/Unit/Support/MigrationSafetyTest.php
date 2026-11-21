<?php

namespace Tests\Unit\Support;

use App\Support\Database\MigrationSafety;
use PHPUnit\Framework\TestCase;

/**
 * The check of make migrations-check over migrations written as fixtures (tests/Fixtures/Migrations): a statement of
 * up() that drops or renames a column or a table needs, on its line or right above it, the release from which the step
 * is safe and why; down(), comments and statements that only add are not findings.
 */
final class MigrationSafetyTest extends TestCase
{
    /**
     * @return list<array{line: int, statement: string}>
     */
    private function findings(string $fixture): array
    {
        return (new MigrationSafety)->findings(dirname(__DIR__, 2).'/Fixtures/Migrations/'.$fixture.'.stub');
    }

    public function test_a_migration_that_only_adds_is_safe_whatever_its_down_and_its_comments_say(): void
    {
        $this->assertSame([], $this->findings('adds-only'));
    }

    public function test_a_column_dropped_without_the_release_from_which_it_is_safe_is_a_finding(): void
    {
        $this->assertSame([['line' => 12, 'statement' => "\$table->dropColumn('legacy_status');"]], $this->findings('drops-a-column'));
    }

    public function test_the_annotation_on_the_line_or_right_above_it_makes_the_step_safe(): void
    {
        $this->assertSame([], $this->findings('annotated'));
    }

    public function test_renames_and_drops_of_raw_sql_and_of_the_schema_are_findings_and_other_sql_is_not(): void
    {
        $this->assertSame([11, 12], array_column($this->findings('raw-sql'), 'line'));
    }

    public function test_an_annotation_above_another_statement_or_without_a_reason_counts_for_nothing(): void
    {
        $this->assertSame([14, 16], array_column($this->findings('annotation-out-of-place'), 'line'));
    }
}
