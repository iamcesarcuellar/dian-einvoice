<?php

namespace App\Modules\Developers\Http\Controllers;

use App\Modules\Developers\Http\Requests\ReceiverModeRequest;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Receiver\ReceiverUnavailable;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The local webhook receiver, a development tool, as one company sees it: the URL its subscriptions
 * use, the mode for its next deliveries and the last webhooks it received, each with the result
 * of the signature check. The browser never talks to the receiver; only this API does.
 */
final class WebhookReceiverController
{
    public function __construct(
        private readonly LocalWebhookReceiver $receiver,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /** The URL, the mode and the last webhooks received, newest first. ?limit= up to 100, 30 by default. */
    public function show(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 30);

        return $this->answer(fn (): array => [
            'url' => $this->receiver->urlFor($this->currentCompany->id()),
            'modes' => LocalWebhookReceiver::MODES,
            ...$this->receiver->status($this->currentCompany->id(), $limit),
        ]);
    }

    /** Change the mode for the next deliveries: normal, error_500 or slow; count limits it to the next N. */
    public function updateMode(ReceiverModeRequest $request): JsonResponse
    {
        return $this->answer(fn (): array => ['mode' => $this->receiver->setMode($this->currentCompany->id(), $request->mode())]);
    }

    /**
     * @param  Closure(): array<string, mixed>  $callback
     */
    private function answer(Closure $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (ReceiverUnavailable $exception) {
            throw new ServiceUnavailableHttpException(null, 'The local webhook receiver is not answering. Start the stack with make up.', $exception);
        }
    }
}
