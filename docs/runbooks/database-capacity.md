# The database near its limits: `database-capacity`, `database-connections` (prod)

| | |
| --- | --- |
| Alarms | `database-capacity`: more than 3.5 of the 4 ACU for 15 minutes; `database-connections`: more than 108 connections (80% of the 135 at maximum capacity) for 5 minutes |
| Service objectives | API latency (capacity), API availability (connections) |
| In the emulator | Prod only: the emulated cloud uses the Compose MySQL, and the database stack is never deployed. `make cloud-artisan ARGS="db:connections"` reads that MySQL's connections the same way |

## What it means and who it affects

Aurora MySQL Serverless v2 scales between 0.5 and 4 ACU. Its maximum capacity sets its `max_connections`: 135 at 4 ACU (docs/infrastructure.md).

- **Near the maximum capacity**, every query slows down. The API gets slower first ([api-latency.md](api-latency.md)), then requests time out.
- **Near the connection limit**, the database refuses new connections. Functions that start a new execution environment fail, and so do the rotation functions and any operator who connects.

The functions can hold 60 connections at most (their reserved concurrency). More than 108 means that something else holds connections, or that the functions were deployed without reserved concurrency.

## Confirm it

1. **Who holds the connections**, by user (the application identity's two alternating users, the migrations identity, RDS Proxy), and how many are idle.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
2. **The capacity and the connections over the last few hours.**
   - Emulator: none (prod only).
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name ServerlessDatabaseCapacity --dimensions Name=DBClusterIdentifier,Value=dian-einvoice-prod-database --start-time <ISO time of 3 hours ago> --end-time <ISO time now> --period 300 --statistics Maximum`, the same command with `--metric-name DatabaseConnections`, and the dashboard's "Database" widgets.
3. **The cluster's current state**: its capacity range and its instances.
   - Emulator: none (prod only).
   - AWS: `aws rds describe-db-clusters --db-cluster-identifier dian-einvoice-prod-database --query "DBClusters[].[Status,ServerlessV2ScalingConfiguration,DBClusterMembers]"`
4. **Slow queries** (more than a second), in the log group of the cluster's slow query log, kept for a year.

## Probable causes

- **More traffic**, which the capacity follows: legitimate growth, or an integration that issues in bulk.
- **A slow query** from a release, or a missing index, that holds connections longer.
- **Connections that stay open**: an operator's session, or a process outside the functions.
- **The functions deployed without reserved concurrency** (`reservedConcurrency: unreserved`): the API can then open as many connections as the account's quota allows.
- **The hourly pruning** of old records, a heavy query for a moment.

## Respond

5. **A release with a slow query**: roll back to the previous image ([api-errors.md](api-errors.md), step 4).
6. **Connections that do not belong to the functions**, such as an operator's session: end them from a session as the migrations identity.
7. **Growth that lasts**: raise the maximum capacity (`maxCapacity`, in the CDK app's `lib/stage.ts`). At 8 ACU the limit is 1,000 connections. The higher `max_connections` applies once the instances reboot, which means a failover to the reader. Or, when the functions' reserved concurrency must outgrow `max_connections`, turn on RDS Proxy (`proxy: true`). Validate the change offline, then deploy the database stack with the workflow.
   - Emulator: `make cdk-check` (the database stack is validated, never deployed to the emulator)
   - AWS: the deployment workflow, run from the commit with the change; it shows the database stack's differences first.
8. **Restore reserved concurrency** as soon as the account's quota allows it (docs/deployment.md).

## Verify the recovery

9. **The connections and the capacity are below their thresholds**, and the alarms are back to `OK`.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-database-capacity dian-einvoice-prod-database-connections --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- A new maximum capacity means a new connection budget. The test that ties the functions' reserved concurrency to `max_connections` (`infra/cdk/test/database.test.ts`) must change with it.
- The cost of capacity: an hour at 4 ACU costs about four times an hour at 1 ACU.
