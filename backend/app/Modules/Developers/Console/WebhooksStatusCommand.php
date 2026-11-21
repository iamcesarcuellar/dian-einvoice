<?php

namespace App\Modules\Developers\Console;

use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\WebhookRetryPolicy;
use App\Modules\Identity\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * An operation command of the runbooks (docs/runbooks/webhook-failures.md): the webhook subscriptions whose
 * deliveries fail, across companies, read only. For each one: its company, the host its URL names (never the path,
 * which may carry a token of the company), whether it is still on, its failures in a row against the ones that switch
 * it off, its failed deliveries in the window and its last answer. Many subscriptions at once point at the way out of
 * the platform; one alone, at the endpoint of its company.
 *
 *   make cloud-artisan ARGS="webhooks:status"
 */
final class WebhooksStatusCommand extends Command
{
    protected $signature = 'webhooks:status {--hours=1 : The window of the failed deliveries}';

    protected $description = 'The webhook subscriptions whose deliveries fail, across companies (an operation command of the runbooks)';

    public function handle(WebhookRetryPolicy $policy): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = Carbon::now()->subHours($hours);
        $deliveries = fn () => WebhookDelivery::query()->withoutGlobalScope(CompanyScope::class);
        $failed = $deliveries()->where('status', WebhookDeliveryStatus::Failed)->where('failed_at', '>=', $since)->selectRaw('subscription_id, count(*) as deliveries')->groupBy('subscription_id')->pluck('deliveries', 'subscription_id');
        $subscriptions = WebhookSubscription::query()->withoutGlobalScope(CompanyScope::class)
            ->where(fn ($query) => $query->whereIn('id', $failed->keys())->orWhere('consecutive_failures', '>', 0)->orWhereNotNull('disabled_at'))
            ->orderByDesc('consecutive_failures')
            ->limit(50)
            ->get();
        $retrying = $deliveries()->where('status', WebhookDeliveryStatus::Pending)->whereNotNull('next_attempt_at')->count();

        $this->line(sprintf('Webhook subscriptions with failures, across companies (deliveries failed in the last %d hours; %d failures in a row switch one off):', $hours, $policy->disableAfter()));

        if ($subscriptions->isEmpty()) {
            $this->line('  none.');
        }

        foreach ($subscriptions as $subscription) {
            $this->line(sprintf(
                '  %s of the company %s, to %s: %s, %d failures in a row, %d deliveries failed, the last answer %s%s.',
                $subscription->id,
                $subscription->company_id,
                (string) (parse_url((string) $subscription->url, PHP_URL_HOST) ?: 'an address without a host'),
                $subscription->status() === 'disabled' ? 'switched off at '.$subscription->disabled_at?->toIso8601ZuluString() : $subscription->status(),
                $subscription->consecutive_failures,
                is_numeric($failed[$subscription->id] ?? null) ? (int) $failed[$subscription->id] : 0,
                $subscription->last_response_code === null ? 'none (no connection)' : "HTTP {$subscription->last_response_code}",
                $subscription->last_delivery_at === null ? '' : ' at '.$subscription->last_delivery_at->toIso8601ZuluString(),
            ));
        }

        $this->line(sprintf('  deliveries waiting for a retry: %d; subscriptions with failures: %d.', $retrying, $subscriptions->count()));

        return self::SUCCESS;
    }
}
