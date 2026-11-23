<?php

namespace App\Modules\Dian\Console;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use App\Support\Console\LocalOnly;
use App\Support\Json;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The steps of make chaos (scripts/chaos.sh), the end-to-end check of the asynchronous chain:
 *  - start: a temporary company issues the invoices and the command prints only its id, so the
 *    script can kill the workers and the relay and restart the simulator while the chain runs;
 *  - finish: waits until every invoice reaches a final state, checks every one was accepted, that
 *    the simulator holds exactly that many distinct accepted documents of the company with no
 *    duplicates, that the numbers have no gaps and that nothing is stuck, prints the attempts and
 *    the time, and deletes the company;
 *  - cleanup: deletes the company when the script stopped before finish.
 */
final class DianChaosCommand extends Command
{
    protected $signature = 'dian:chaos
        {action : start, finish or cleanup}
        {company? : The id start printed, for finish and cleanup}
        {--invoices=40 : Invoices to issue}
        {--timeout=120 : Seconds to wait for every invoice to reach a final state}';

    protected $description = 'Steps of make chaos: issue invoices on a temporary company, then check every one was accepted exactly once after the workers, the relay and the simulator restarted';

    public function handle(DocumentDrafts $drafts, IssueDocument $issue, DianSimulator $simulator): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'start' => $this->start($drafts, $issue),
            'finish' => $this->finish($simulator),
            'cleanup' => $this->cleanup(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error('The action is start, finish or cleanup.');

        return self::FAILURE;
    }

    private function start(DocumentDrafts $drafts, IssueDocument $issue): int
    {
        $count = max(1, (int) $this->option('invoices'));
        $temporary = TemporaryCompany::create('Chaos Test', 'CH');

        try {
            $template = $temporary->numbering();
            $customer = $temporary->customer();
            $taxId = $temporary->iva();
            $ids = [];

            for ($index = 1; $index <= $count; $index++) {
                $ids[] = $drafts->create(DocumentType::Invoice, [
                    'contact_id' => $customer->id,
                    'number_template_id' => $template->id,
                    'lines' => [
                        ['description' => 'Chaos test product', 'quantity' => '3', 'unit_price' => '12000', 'tax_id' => $taxId],
                        ['description' => 'Chaos test service', 'quantity' => '1', 'unit_price' => (string) (500 * $index), 'tax_id' => null],
                    ],
                ], $temporary->user)->id;
            }

            Cache::put($this->startedKey($temporary), microtime(true), 3600);

            foreach ($ids as $id) {
                $issue($id, $temporary->user);
            }
        } catch (Throwable $exception) {
            $temporary->delete();

            throw $exception;
        }

        $this->progress("Temporary company {$temporary->company->name} (NIT {$temporary->company->identification_number}): {$count} invoices issued; the chain is running.");
        $this->line($temporary->company->id);

        return self::SUCCESS;
    }

    private function finish(DianSimulator $simulator): int
    {
        $temporary = $this->temporary();

        if ($temporary === null) {
            return self::FAILURE;
        }

        try {
            return $this->check($temporary, $simulator) ? self::SUCCESS : self::FAILURE;
        } finally {
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }
    }

    private function check(TemporaryCompany $temporary, DianSimulator $simulator): bool
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $waitStarted = microtime(true);
        $this->line("Waiting up to {$timeout} s for every invoice to reach a final state...");

        do {
            $documents = Document::query()->where('status', DocumentStatus::Issued)->orderBy('number')->get();
            $pending = $documents->filter(fn (Document $document): bool => $document->electronic_status?->isFinal() !== true)->count();

            if ($documents->isNotEmpty() && $pending === 0) {
                break;
            }

            usleep(500_000);
        } while (microtime(true) - $waitStarted < $timeout);

        $now = microtime(true);
        $cached = Cache::get($this->startedKey($temporary));
        $started = is_numeric($cached) ? (float) $cached : $waitStarted;
        $count = $documents->count();
        $byStatus = $documents->countBy(fn (Document $document): string => $document->electronic_status->value ?? 'none')->all();
        $accepted = $byStatus['accepted'] ?? 0;

        // Numbers: consecutive from the start of the range, no gaps, no repeats.
        $numbers = $documents->pluck('number')->map(fn (mixed $number): int => is_numeric($number) ? (int) $number : 0)->all();
        $first = $numbers[0] ?? 0;
        $gaps = $numbers === [] ? [] : array_values(array_diff(range($first, max($numbers)), $numbers));
        $repeats = count($numbers) - count(array_unique($numbers));

        // The simulator: exactly one accepted record per invoice, all distinct, nothing else.
        try {
            $simulated = $simulator->documents($temporary->company->identification_number);
        } catch (Throwable $exception) {
            $this->error('The simulator did not respond: '.$exception->getMessage());
            $simulated = [];
        }

        $records = collect($simulated);

        $simulatorAccepted = $records->where('status', 'accepted');
        $ours = $documents->pluck('cufe')->filter()->all();
        $distinctCufes = $simulatorAccepted->pluck('cufe')->unique()->count();
        $distinctNumbers = $simulatorAccepted->pluck('number')->unique()->count();
        $foreign = $simulatorAccepted->reject(fn (array $record): bool => in_array($record['cufe'] ?? null, $ours, true))->count();
        $repeatedSubmissions = (int) $records->sum(fn (array $record): int => max(0, (Json::int($record, 'submissions') ?? 1) - 1));

        // Calls and attempts recorded by the chain.
        $calls = DianTransmission::query()->get();
        $attempts = $calls->map(fn (DianTransmission $call): string => $call->document_id.'/'.$call->run.'/'.$call->attempt)->unique()->count();
        $unfinished = $calls->whereNull('finished_at')->count();
        $abandoned = $calls->where('outcome', TransmissionOutcome::Abandoned)->count();
        $stuck = $count - ($byStatus['accepted'] ?? 0) - ($byStatus['rejected'] ?? 0) - ($byStatus['failed'] ?? 0);

        $checks = [
            sprintf('%d of %d invoices accepted by the local simulator (%s).', $accepted, $count, $this->states($byStatus)) => $count > 0 && $accepted === $count,
            sprintf('The simulator holds %d distinct accepted documents of NIT %s (%d distinct numbers, %d not ours, %d records in all).', $distinctCufes, $temporary->company->identification_number, $distinctNumbers, $foreign, $records->count()) => $distinctCufes === $count && $distinctNumbers === $count && $foreign === 0 && $records->count() === $count,
            sprintf('No duplicates: %d repeated submissions rejected with rule 90, %d repeated numbers.', $repeatedSubmissions, $repeats) => $repeats === 0 && $simulatorAccepted->count() === $distinctCufes,
            sprintf('Numbers %d to %d: %s.', $first, $numbers === [] ? 0 : max($numbers), $gaps === [] ? 'consecutive, no gaps' : 'gaps at '.implode(', ', array_slice($gaps, 0, 10))) => $gaps === [] && $first === 1,
            sprintf('No document stuck: %d still processing, %d calls left unfinished.', $stuck, $unfinished) => $stuck === 0 && $unfinished === 0,
        ];

        $this->newLine();
        $passed = true;

        foreach ($checks as $message => $ok) {
            $ok ? $this->info("   PASS  {$message}") : $this->error("   FAIL  {$message}");
            $passed = $passed && $ok;
        }

        $this->newLine();
        $this->line(sprintf(
            '   Attempts: %d transmission attempts for %d invoices, %d calls (%d SendBillSync, %d GetStatus), %d taken over after a worker died.',
            $attempts, $count, $calls->count(), $calls->where('operation', 'SendBillSync')->count(), $calls->where('operation', 'GetStatus')->count(), $abandoned,
        ));
        $this->line(sprintf('   Time: %.1f s from the first issue until every invoice was final (%.1f s of waiting).', $now - $started, $now - $waitStarted));
        $this->newLine();

        $passed ? $this->info('make chaos passed.') : $this->error('make chaos failed.');

        return $passed;
    }

    private function cleanup(): int
    {
        $this->temporary()?->delete();

        return self::SUCCESS;
    }

    private function temporary(): ?TemporaryCompany
    {
        $id = (string) $this->argument('company');
        $temporary = $id === '' ? null : TemporaryCompany::find($id);

        if ($temporary === null) {
            $this->error("There is no temporary company {$id}.");
        }

        return $temporary;
    }

    /**
     * @param  array<array-key, int>  $byStatus
     */
    private function states(array $byStatus): string
    {
        return collect($byStatus)->map(fn (int $total, int|string $state): string => "{$total} {$state}")->implode(', ') ?: 'none';
    }

    private function startedKey(TemporaryCompany $temporary): string
    {
        return 'dian-chaos:started:'.$temporary->company->id;
    }

    private function progress(string $message): void
    {
        $this->getOutput()->getErrorStyle()->writeln($message);
    }
}
