<?php

namespace App\Modules\Workspace\Console;

use App\Modules\Delivery\Support\ColombianFormat;
use App\Support\Json;
use Brick\Math\BigDecimal;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Development check of the two answers the dashboard and the command palette of the web app stand
 * on, over HTTP and signed in the way the web app signs in: a Sanctum session cookie with its CSRF
 * token, sent with the origin of the web app so Sanctum treats the requests as stateful. It signs in
 * as the synthetic demo user, reads GET /dashboard and GET /search?q=SETP of Demo Comercial SAS (the
 * data of make demo) and signs out. It only reads.
 */
final class UiCheckCommand extends Command
{
    protected $signature = 'ui:check';

    protected $description = 'Sign in as the demo user over HTTP and check that GET /dashboard and GET /search?q=SETP of Demo Comercial SAS return data';

    /** The origin of the web app, one of SANCTUM_STATEFUL_DOMAINS. */
    private const string WEB_ORIGIN = 'http://localhost:8601';

    /** The synthetic demo user and company of make demo. */
    private const string EMAIL = 'demo@example.com';

    private const string PASSWORD = 'password';

    private const string COMPANY = 'Demo Comercial SAS';

    /** The prefix of the demo invoice numbering (the DIAN test set). */
    private const string QUERY = 'SETP';

    private string $api;

    private string $origin;

    private CookieJar $cookies;

    private int $failures = 0;

    public function handle(): int
    {
        $this->api = rtrim((string) config('developers.api_check_url'), '/');
        $this->origin = $this->originOf($this->api);
        $this->cookies = new CookieJar;

        $this->line(sprintf('Signing in as the web app does (Sanctum session and CSRF cookie, origin %s), over HTTP to %s.', self::WEB_ORIGIN, $this->api));

        try {
            $this->runChecks();
        } catch (Throwable $exception) {
            $this->recordFailure('the check stopped: '.$exception->getMessage());
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("ui-check: {$this->failures} checks FAILED.");

            return self::FAILURE;
        }

        $this->info('ui-check: every check passed.');

        return self::SUCCESS;
    }

    private function runChecks(): void
    {
        $csrf = $this->http()->get($this->origin.'/sanctum/csrf-cookie');

        if (! $this->check($csrf->status() === 204 && $this->xsrfToken() !== null, 'GET /sanctum/csrf-cookie sets the XSRF-TOKEN cookie', "GET /sanctum/csrf-cookie answered {$csrf->status()}".($this->xsrfToken() === null ? ' without an XSRF-TOKEN cookie' : ''))) {
            return;
        }

        $login = $this->http()
            ->withHeaders(['X-XSRF-TOKEN' => (string) $this->xsrfToken()])
            ->post($this->api.'/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        if (! $this->expect($login, 200, 'POST /auth/login as '.self::EMAIL.' (synthetic demo user)')) {
            return;
        }

        $me = $this->http()->get($this->api.'/auth/me');
        $company = collect(Json::objects($me->json(), 'data.companies'))->firstWhere('name', self::COMPANY);

        if (! $this->check($me->status() === 200 && is_array($company), 'GET /auth/me lists '.self::COMPANY, 'GET /auth/me answered '.$me->status().' without '.self::COMPANY.': run make demo first')) {
            return;
        }

        $tenant = ['X-Company-Id' => Json::text($company, 'id') ?? ''];

        try {
            $this->checkDashboard($this->http()->withHeaders($tenant)->get($this->api.'/dashboard'));
            $this->checkSearch($this->http()->withHeaders($tenant)->get($this->api.'/search', ['q' => self::QUERY]));
        } finally {
            $logout = $this->http()
                ->withHeaders(['X-XSRF-TOKEN' => (string) $this->xsrfToken()])
                ->post($this->api.'/auth/logout');

            $this->expect($logout, 204, 'POST /auth/logout');
        }
    }

    private function checkDashboard(Response $response): void
    {
        if (! $this->expect($response, 200, 'GET /dashboard of '.self::COMPANY)) {
            return;
        }

        $data = Json::array($response->json(), 'data');
        $monthly = Json::objects($data, 'monthly');
        $monthsWithInvoices = count(array_filter($monthly, fn (array $month): bool => $this->positive($month['invoiced'] ?? null)));
        $topCustomers = count(Json::array($data, 'top_customers'));
        $activity = count(Json::array($data, 'activity'));
        $invoices = Json::int($data, 'kpis.invoiced.count') ?? 0;

        $this->check(($data['has_invoices'] ?? false) === true, 'has_invoices is true', 'has_invoices is not true');
        $this->check($invoices > 0, "{$invoices} invoices accepted this month", 'kpis.invoiced.count is 0: no accepted invoice this month');
        $this->check(count($monthly) === 6 && $monthsWithInvoices > 0, "6 months in the series, {$monthsWithInvoices} with invoices", sprintf('monthly has %d rows, %d with invoices', count($monthly), $monthsWithInvoices));
        $this->check($this->positive(data_get($data, 'aging.total')), 'the receivables by age add up to more than zero', 'aging.total is not above zero');
        $this->check($topCustomers > 0, "{$topCustomers} top customers", 'top_customers is empty');
        $this->check($activity > 0, "{$activity} activity entries", 'activity is empty');

        $change = Json::text($data, 'kpis.invoiced.change');

        $this->detail(sprintf(
            'dashboard: %d invoices this month for %s (%s), %s collected, %s receivable, %s overdue in %d invoices, %d needing attention, %d activity entries',
            $invoices,
            $this->money(data_get($data, 'kpis.invoiced.amount')),
            $change === null ? 'nothing invoiced on the same days of last month' : "{$change} % against the same days of last month",
            $this->money(data_get($data, 'kpis.collected.amount')),
            $this->money(data_get($data, 'kpis.receivable.amount')),
            $this->money(data_get($data, 'kpis.overdue.amount')),
            Json::int($data, 'kpis.overdue.count') ?? 0,
            Json::int($data, 'attention.total') ?? 0,
            $activity,
        ));
    }

    private function checkSearch(Response $response): void
    {
        if (! $this->expect($response, 200, 'GET /search?q='.self::QUERY.' of '.self::COMPANY)) {
            return;
        }

        $groups = Json::array($response->json(), 'data.groups');
        $invoices = Json::int($groups, 'invoices.total') ?? 0;
        $numbers = array_map(fn (array $item): string => Json::text($item, 'full_number') ?? '', Json::objects($groups, 'invoices.items'));
        $matching = array_filter($numbers, fn (string $number): bool => str_contains($number, self::QUERY));

        $this->check($invoices > 0, sprintf('%d invoices match %s', $invoices, self::QUERY), 'groups.invoices.total is 0');
        $this->check($numbers !== [] && count($matching) === count($numbers), sprintf('every invoice shown has %s in its number (%s)', self::QUERY, implode(', ', $numbers)), sprintf('invoice numbers without %s: %s', self::QUERY, implode(', ', array_diff($numbers, $matching)) ?: 'none shown'));

        $this->detail(sprintf(
            'search ?q=%s: %d invoices (%d shown), %d notes, %d payments, %d contacts, %d items',
            self::QUERY,
            $invoices,
            count($numbers),
            Json::int($groups, 'notes.total') ?? 0,
            Json::int($groups, 'payments.total') ?? 0,
            Json::int($groups, 'contacts.total') ?? 0,
            Json::int($groups, 'items.total') ?? 0,
        ));
    }

    /** A request as the web app makes it: the shared cookie jar, JSON, and its origin. */
    private function http(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->cookies])
            ->withHeaders(['Origin' => self::WEB_ORIGIN, 'Referer' => self::WEB_ORIGIN.'/'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    /** The CSRF token Sanctum left in the XSRF-TOKEN cookie, as the X-XSRF-TOKEN header takes it. */
    private function xsrfToken(): ?string
    {
        $cookie = $this->cookies->getCookieByName('XSRF-TOKEN');

        return $cookie === null ? null : urldecode((string) $cookie->getValue());
    }

    /** "http://127.0.0.1:8000/api/v1" becomes "http://127.0.0.1:8000". */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '127.0.0.1').$port;
    }

    private function positive(mixed $amount): bool
    {
        return is_string($amount) && is_numeric($amount) && BigDecimal::of($amount)->isPositive();
    }

    private function money(mixed $amount): string
    {
        return is_string($amount) && is_numeric($amount) ? ColombianFormat::money($amount) : '?';
    }

    private function expect(Response $response, int $status, string $label): bool
    {
        return $this->check(
            $response->status() === $status,
            "{$label}: {$status}",
            "{$label}: expected {$status}, got {$response->status()} ".Str::limit(Json::text($response->json(), 'detail') ?? $response->body(), 300),
        );
    }

    private function check(bool $passed, string $label, string $failure): bool
    {
        if ($passed) {
            $this->line("  <info>✓</info> {$label}");

            return true;
        }

        $this->recordFailure($failure);

        return false;
    }

    private function recordFailure(string $message): void
    {
        $this->failures++;
        $this->line("  <error>✗ FAIL</error> {$message}");
    }

    private function detail(string $message): void
    {
        $this->line("      <fg=gray>{$message}</>");
    }
}
