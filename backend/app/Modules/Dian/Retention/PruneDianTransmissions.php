<?php

namespace App\Modules\Dian\Retention;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Support\Retention\Pruner;
use App\Support\Retention\Retention;
use App\Support\Storage\DocumentStorage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Calls to the DIAN (dian_transmissions) of documents it accepted, finished before the cutoff, and
 * the ApplicationResponse file each one stored, except the one the document keeps as its answer.
 * The calls of documents not accepted yet stay: their chain may still read them.
 */
final class PruneDianTransmissions implements Pruner
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function name(): string
    {
        return 'dian_transmissions';
    }

    public function prune(CarbonInterface $before): int
    {
        return Retention::inBatches(function () use ($before): int {
            $rows = DB::table('dian_transmissions as t')
                ->join('documents as d', 'd.id', '=', 't.document_id')
                ->where('d.electronic_status', ElectronicStatus::Accepted->value)
                ->whereNotNull('t.finished_at')
                ->where('t.finished_at', '<', $before)
                ->orderBy('t.id')
                ->limit(Retention::BATCH)
                ->get(['t.id', 't.response_path', 'd.dian_response_path']);

            foreach ($rows as $row) {
                /** @var stdClass $row */
                if (is_string($row->response_path) && $row->response_path !== $row->dian_response_path && $this->storage->exists($row->response_path)) {
                    $this->storage->delete($row->response_path);
                }
            }

            return DB::table('dian_transmissions')->whereIn('id', $rows->pluck('id')->all())->delete();
        });
    }
}
