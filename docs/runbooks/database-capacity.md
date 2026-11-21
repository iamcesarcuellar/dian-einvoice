# The database near its limits: `database-capacity`, `database-connections` (prod)

| | |
| --- | --- |
| Alarms | `database-capacity`: more than 3.5 of the 4 ACU for 15 minutes; `database-connections`: more than 108 connections, 80% of the 135 at the maximum capacity, for 5 minutes |
| Objectives | API latency (capacity), API availability (connections) |
| In the emulator | prod only: the emulated cloud keeps the MySQL of compose, and the stack database is never deployed. `make cloud-artisan ARGS="db:connections"` reads the connections of that MySQL the same way |

## What it means and who it affects

Aurora MySQL Serverless v2 grows between 0.5 and 4 ACU, and its `max_connections` is fixed by its maximum: 135 at 4 ACU (docs/infrastructure.md). Near the maximum capacity, every query slows down: the API gets slower first ([api-latency.md](api-latency.md)), then requests time out. Near the connections, new connections are refused: functions that start a new execution environment fail, and so do the rotation functions and an operator who connects. The functions may hold 60 at most (their reserved concurrency), so more than 108 means something else holds connections, or the functions deploy without reserved concurrency.

## Confirm it

1. **Who holds the connections**, by user (the application identity with its two alternating users, the migrations identity, RDS Proxy) and how many are idle.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
2. **The capacity and the connections over the last hours.**
   - Emulator: none (prod only).
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name ServerlessDatabaseCapacity --dimensions Name=DBClusterIdentifier,Value=dian-einvoice-prod-database --start-time <ISO time of 3 hours ago> --end-time <ISO time now> --period 300 --statistics Maximum`, the same with `--metric-name DatabaseConnections`, and the widgets "Database" of the dashboard.
3. **The cluster as it is now**: its capacity range and its instances.
   - Emulator: none (prod only).
   - AWS: `aws rds describe-db-clusters --db-cluster-identifier dian-einvoice-prod-database --query "DBClusters[].[Status,ServerlessV2ScalingConfiguration,DBClusterMembers]"`
4. **Slow queries** (more than a second), in the log group of the slow query log of the cluster, kept a year.

## Probable causes

- **More traffic** that the capacity follows: legitimate growth, an integration that issues in bulk.
- **A slow query** of a release, or a missing index, that holds connections longer.
- **Connections that stay open**: an operator's session, a process outside the functions.
- **The functions deployed without reserved concurrency** (`reservedConcurrency: unreserved`): the API may then open as many connections as the quota of the account lets it.
- **The pruning** of old records once an hour, a heavy query for a moment.

## Respond

5. **A release with a slow query**: back to the image before it ([api-errors.md](api-errors.md), step 4).
6. **Connections that are not of the functions**: end them (an operator's session) from a session as the migrations identity.
7. **Growth that lasts**: raise the maximum capacity in `lib/stage.ts` of the CDK app (`maxCapacity`; 8 ACU gives 1,000 connections, and `max_connections` changes once the instances reboot, a failover to the reader), or turn on RDS Proxy (`proxy: true`) when the reserved concurrency of the functions must outgrow `max_connections`. Validate the change offline, then deploy the stack database with the workflow.
   - Emulator: `make cdk-check` (the stack database is validated, never deployed to the emulator)
   - AWS: the deployment workflow from the commit with the change; it shows the differences of the stack database first.
8. **Reserved concurrency again** as soon as the quota of the account allows it (docs/deployment.md).

## Verify the recovery

9. **The connections and the capacity below their thresholds**, and the alarms back to `OK`.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-database-capacity dian-einvoice-prod-database-connections --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- A new maximum capacity is a new budget of connections: the test that ties the reserved concurrency of the functions to `max_connections` (`infra/cdk/test/database.test.ts`) moves with it.
- The cost of the capacity: an hour at 4 ACU is about four times one at 1 ACU.
