<?php

namespace App\Modules\Dian\Http\Resources;

use App\Modules\Dian\Models\DianTransmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One call to the local DIAN simulator: attempt, operation, duration, result code and error.
 *
 * @mixin DianTransmission
 */
final class DianTransmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run' => $this->run,
            'attempt' => $this->attempt,
            // SendBillSync or GetStatus.
            'operation' => $this->operation,
            'gateway' => $this->gateway,
            'zip_file_name' => $this->zip_file_name,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_ms' => $this->duration_ms,
            // responded, found, not_found, timeout, no_response, refused, server_error,
            // invalid_response, client_error or abandoned; null while the call runs.
            'outcome' => $this->outcome,
            'http_status' => $this->http_status,
            'status_code' => $this->status_code,
            'status_description' => $this->status_description,
            'is_valid' => $this->is_valid,
            'error' => $this->error,
        ];
    }
}
