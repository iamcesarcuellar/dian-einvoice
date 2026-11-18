<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Development tool. On a temporary company of its own, it creates drafts, issues them from
 * parallel processes and checks the numbers are consecutive, with no gaps or repeats. Then it
 * waits for the whole electronic document chain (XML, signature, transmission to the local DIAN
 * simulator and its answer, run by the queue workers) and reports the time from the first issue
 * to the last acceptance and the invoices per second. The company is deleted at the end.
 */
final class StressNumberingCommand extends Command
{
    protected $signature = 'billing:stress
        {--invoices=60 : Invoices to issue}
        {--concurrency=20 : Processes issuing at the same time}
        {--timeout= : Seconds to wait for the chain; 120 plus 2 per invoice by default}';

    protected $description = 'Issue invoices from parallel processes on a temporary company, check the numbering, and time the chain until the DIAN simulator accepts them';

    public function handle(DocumentDrafts $drafts, IssueDocument $issue): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('The pcntl extension is missing; rebuild the api image with make up.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('invoices'));
        $workers = max(1, min($count, (int) $this->option('concurrency')));
        $timeout = (int) ($this->option('timeout') ?: 120 + 2 * $count);

        $temporary = TemporaryCompany::create('Stress Test', 'ST');

        try {
            $template = $temporary->numbering();
            $customer = $temporary->customer();
            $taxId = $temporary->iva();
            $ids = [];

            for ($index = 1; $index <= $count; $index++) {
                $ids[] = $drafts->create(DocumentType::Invoice, [
                    'contact_id' => $customer->getKey(),
                    'number_template_id' => $template->getKey(),
                    'lines' => [
                        ['description' => 'Stress test product', 'quantity' => '2', 'unit_price' => '15000', 'tax_id' => $taxId],
                        ['description' => 'Stress test service', 'quantity' => '1', 'unit_price' => (string) (1000 * $index), 'tax_id' => null],
                    ],
                ], $temporary->user)->getKey();
            }

            $this->line("Temporary company {$temporary->company->name}: {$count} drafts created.");

            $started = hrtime(true);
            $failures = $this->issueInParallel($ids, $workers, $issue, $temporary->user);
            $issuedIn = (hrtime(true) - $started) / 1e9;

            $numbering = $this->reportNumbering($template->refresh(), $count, $workers, $failures, $issuedIn);
            $chain = $this->reportChain($count, $started, $timeout);

            return $numbering && $chain ? self::SUCCESS : self::FAILURE;
        } finally {
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }
    }

    /**
     * Forks the processes; each one issues its share of the drafts with its own database connection.
     *
     * @param  list<string>  $ids
     * @return int Issues that failed.
     */
    private function issueInParallel(array $ids, int $workers, IssueDocument $issue, User $user): int
    {
        // A forked child must never share the parent's connection.
        DB::disconnect();

        $children = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not start a process.');
            }

            if ($pid === 0) {
                $failed = 0;

                foreach ($ids as $index => $id) {
                    if ($index % $workers !== $worker) {
                        continue;
                    }

                    try {
                        $issue($id, $user);
                    } catch (Throwable $exception) {
                        $failed++;
                        fwrite(STDERR, sprintf("Process %d, draft %s: %s\n", $worker + 1, $id, $exception->getMessage()));
                    }
                }

                DB::disconnect();

                exit(min($failed, 255));
            }

            $children[] = $pid;
        }

        $failed = 0;

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $failed += pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        }

        return $failed;
    }

    private function reportNumbering(NumberTemplate $template, int $count, int $workers, int $failures, float $seconds): bool
    {
        $numbers = Document::query()
            ->where('status', DocumentStatus::Issued)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($number): int => (int) $number)
            ->all();

        $issued = count($numbers);
        $first = $numbers[0] ?? 0;
        $last = $numbers === [] ? 0 : $numbers[$issued - 1];
        $repeated = array_values(array_unique(array_diff_assoc($numbers, array_unique($numbers))));
        $missing = $numbers === [] ? [] : array_values(array_diff(range($first, $last), $numbers));

        $this->line(sprintf('Issued %d of %d invoices from %d parallel processes in %.2f s (%d failed).', $issued, $count, $workers, $seconds, $failures));

        $ok = $failures === 0
            && $issued === $count
            && $repeated === []
            && $missing === []
            && $first === $template->from_number
            && $template->next_number === $template->from_number + $count;

        if ($ok) {
            $this->info(sprintf('Numbers %s%d to %s%d: %d consecutive numbers, no gaps and no repeats.', $template->prefix, $first, $template->prefix, $last, $issued));

            return true;
        }

        $this->error(sprintf(
            'Numbering check failed: first %d, last %d, next %d, repeated [%s], missing [%s].',
            $first, $last, $template->next_number, implode(', ', $repeated), implode(', ', array_slice($missing, 0, 20)),
        ));

        return false;
    }

    /** Waits until every issued invoice reaches a final state, or the timeout, and reports. */
    private function reportChain(int $count, int $started, int $timeout): bool
    {
        $this->line('Waiting for the chain: XML, signature, transmission to the local DIAN simulator and its answer...');

        $deadline = $started + $timeout * 1_000_000_000;

        do {
            $states = $this->states();
            $final = ($states[ElectronicStatus::Accepted->value] ?? 0) + ($states[ElectronicStatus::Rejected->value] ?? 0) + ($states[ElectronicStatus::Failed->value] ?? 0);

            if ($final >= $count) {
                break;
            }

            usleep(250_000);
        } while (hrtime(true) < $deadline);

        $seconds = (hrtime(true) - $started) / 1e9;
        $accepted = $states[ElectronicStatus::Accepted->value] ?? 0;
        $calls = DianTransmission::query()->toBase()->selectRaw('operation, count(*) as total')->groupBy('operation')->pluck('total', 'operation');

        $this->line(sprintf(
            'Chain: %d of %d accepted by the local DIAN simulator, %d rejected, %d failed, %d still processing, in %.2f s from the first issue: %.2f invoices per second.',
            $accepted, $count, $states[ElectronicStatus::Rejected->value] ?? 0, $states[ElectronicStatus::Failed->value] ?? 0, $count - $final, $seconds, $accepted / max($seconds, 0.001),
        ));
        $this->line(sprintf('Calls to the simulator: %d SendBillSync, %d GetStatus.', $calls['SendBillSync'] ?? 0, $calls['GetStatus'] ?? 0));

        if ($accepted === $count) {
            $this->info("Every invoice went from issue to acceptance in {$this->seconds($seconds)}.");

            return true;
        }

        $this->error('Not every invoice was accepted within '.$timeout.' s.');

        return false;
    }

    /**
     * @return array<string, int> Issued invoices by electronic status.
     */
    private function states(): array
    {
        return Document::query()
            ->where('status', DocumentStatus::Issued)
            ->toBase()
            ->selectRaw('electronic_status, count(*) as total')
            ->groupBy('electronic_status')
            ->pluck('total', 'electronic_status')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private function seconds(float $seconds): string
    {
        return sprintf('%.2f s', $seconds);
    }
}
