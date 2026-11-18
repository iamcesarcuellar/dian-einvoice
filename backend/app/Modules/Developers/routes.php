<?php

use App\Http\ApiMiddleware;
use App\Modules\Developers\Http\Controllers\ApiKeyController;
use App\Modules\Developers\Http\Controllers\WebhookDeliveryController;
use App\Modules\Developers\Http\Controllers\WebhookReceiverController;
use App\Modules\Developers\Http\Controllers\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

// Developers: API keys, webhook subscriptions and their deliveries, and the local webhook receiver.
// Web app session of owners and admins only: API keys get 403. Answers that carry a secret, shown
// once, are never kept by the Idempotency-Key layer.
Route::middleware([...ApiMiddleware::PANEL, 'permission:manage-settings'])->group(function (): void {
    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->withoutMiddleware('idempotency')->name('api-keys.store');
    Route::post('api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');

    Route::get('webhooks/event-types', [WebhookSubscriptionController::class, 'eventTypes'])->name('webhooks.event-types');
    Route::get('webhooks', [WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
    Route::post('webhooks', [WebhookSubscriptionController::class, 'store'])->withoutMiddleware('idempotency')->name('webhooks.store');
    Route::get('webhooks/{subscription}', [WebhookSubscriptionController::class, 'show'])->name('webhooks.show');
    Route::patch('webhooks/{subscription}', [WebhookSubscriptionController::class, 'update'])->name('webhooks.update');
    Route::delete('webhooks/{subscription}', [WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('webhooks/{subscription}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])->withoutMiddleware('idempotency')->name('webhooks.rotate-secret');
    Route::post('webhooks/{subscription}/test', [WebhookSubscriptionController::class, 'test'])->name('webhooks.test');
    Route::get('webhooks/{subscription}/deliveries', [WebhookDeliveryController::class, 'index'])->name('webhooks.deliveries');

    Route::get('webhook-deliveries/{delivery}', [WebhookDeliveryController::class, 'show'])->name('webhook-deliveries.show');
    Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])->name('webhook-deliveries.redeliver');

    // The local webhook receiver, a development tool inside the simulator service.
    Route::get('webhook-receiver', [WebhookReceiverController::class, 'show'])->name('webhook-receiver.show');
    Route::put('webhook-receiver/mode', [WebhookReceiverController::class, 'updateMode'])->name('webhook-receiver.mode');
});
