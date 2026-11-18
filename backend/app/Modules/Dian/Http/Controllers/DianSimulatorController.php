<?php

namespace App\Modules\Dian\Http\Controllers;

use App\Modules\Dian\Http\Requests\SimulatorModeRequest;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Dian\Simulator\SimulatorUnavailable;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The local DIAN simulator, a development tool, as seen by one company: the failure mode that
 * applies to the requests of its NIT and the recent requests it received from it. The browser
 * never talks to the simulator; only this API does. Owners and admins only.
 */
final class DianSimulatorController
{
    public function __construct(
        private readonly DianSimulator $simulator,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * The current failure mode of the company, the modes available and the rules a rejection can use.
     */
    public function mode(): JsonResponse
    {
        return $this->answer(fn (): array => [
            'mode' => $this->simulator->mode($this->nit()),
            'modes' => DianSimulator::MODES,
            'rules' => $this->simulator->rules(),
        ]);
    }

    /**
     * Change the failure mode for the requests of the company: normal; reject with a rule; no
     * response; error 500; slow, with a delay in milliseconds; or intermittent, with a failure
     * percentage. count limits it to the next N requests; without it the mode stays until changed.
     */
    public function updateMode(SimulatorModeRequest $request): JsonResponse
    {
        return $this->answer(function () use ($request): array {
            $mode = $request->mode();

            if ($mode['mode'] === 'reject' && ! in_array($mode['rule'], array_column($this->simulator->rules(), 'code'), true)) {
                throw ValidationException::withMessages(['rule' => ['The local simulator does not implement this rule.']]);
            }

            return ['mode' => $this->simulator->setMode($this->nit(), $mode)];
        });
    }

    /**
     * The last requests the simulator received from the company, newest first: time, operation,
     * document, result and duration. ?limit= up to 200, 50 by default.
     */
    public function requests(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:200']])['limit'] ?? 50);

        return $this->answer(fn (): array => $this->simulator->requests($this->nit(), $limit));
    }

    private function nit(): string
    {
        return $this->currentCompany->company()->identification_number;
    }

    /**
     * @param  Closure(): array<mixed>  $callback
     */
    private function answer(Closure $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (SimulatorUnavailable $exception) {
            throw new ServiceUnavailableHttpException(null, 'The local DIAN simulator is not answering. Start it with make up.', $exception);
        }
    }
}
