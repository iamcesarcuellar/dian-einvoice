<?php

namespace App\Support\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * An operation command of the runbooks (docs/runbooks/database-capacity.md): who holds the connections of the
 * database now, read only. The most the server takes (max_connections), how many are open and the most ever open
 * since it started, and the open ones by user and state: the application identity (every function but the console,
 * with its two alternating users), the migrations identity (the console function, the rotation functions), RDS Proxy
 * when on. The console function connects as the migrations identity, which sees every connection with the privilege
 * PROCESS (the master user of Aurora holds it; the stage local grants it); without it MySQL shows its own only.
 *
 *   make cloud-artisan ARGS="db:connections"
 */
final class ConnectionsCommand extends Command
{
    protected $signature = 'db:connections';

    protected $description = 'Who holds the connections of the database now, by user and state (an operation command of the runbooks)';

    /** The connections the functions may hold at once, their reserved concurrency (docs/infrastructure.md). */
    public const int FUNCTIONS_BUDGET = 60;

    public function handle(): int
    {
        $connection = DB::connection();
        $variable = fn (string $query): int => (int) (ApplicationIdentityCommand::firstColumn([$connection->selectOne($query)], 1)[0] ?? 0);
        $max = $variable("SHOW VARIABLES LIKE 'max_connections'");
        $open = $variable("SHOW GLOBAL STATUS LIKE 'Threads_connected'");
        $most = $variable("SHOW GLOBAL STATUS LIKE 'Max_used_connections'");
        $current = $connection->selectOne('SELECT CURRENT_USER() AS user');
        $rows = $connection->select("SELECT user, command = 'Sleep' AS idle, count(*) AS connections FROM information_schema.processlist GROUP BY user, command = 'Sleep' ORDER BY user");
        $byUser = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $user = is_string($values['user'] ?? null) ? $values['user'] : '?';
            $byUser[$user] ??= ['open' => 0, 'idle' => 0];
            $connections = is_numeric($values['connections'] ?? null) ? (int) $values['connections'] : 0;
            $byUser[$user]['open'] += $connections;
            $byUser[$user]['idle'] += is_numeric($values['idle'] ?? null) && (int) $values['idle'] === 1 ? $connections : 0;
        }

        $this->line(sprintf('The connections of the database %s, seen as %s:', $connection->getDatabaseName(), is_object($current) && is_string($current->user ?? null) ? $current->user : '?'));
        $this->line(sprintf('  max_connections %d; open now %d; the most open at once since the server started %d.', $max, $open, $most));

        foreach ($byUser as $user => $counts) {
            $this->line(sprintf('    %-28s %3d open, %d of them idle', $user, $counts['open'], $counts['idle']));
        }

        if (count($byUser) <= 1 && $open > 1) {
            $this->line('  Only this connection is listed: the identity lacks the privilege PROCESS, which shows the others.');
        }

        $this->line(sprintf('  The functions may hold %d at once (their reserved concurrency); %d of %d are open, %d%% of the server.', self::FUNCTIONS_BUDGET, $open, $max, $max === 0 ? 0 : (int) round(100 * $open / $max)));

        return self::SUCCESS;
    }
}
