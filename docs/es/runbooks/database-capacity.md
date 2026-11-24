# La base de datos cerca de sus límites: `database-capacity`, `database-connections` (producción)

[English](../../runbooks/database-capacity.md) · **Español**

<!-- Traducción de docs/runbooks/database-capacity.md con SHA-256 e415b0e170ccb002f79530b114ef40064e2559a1d12c9feeccbd11a550b1ec3d. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarmas | `database-capacity`: más de 3,5 de las 4 ACU durante 15 minutos; `database-connections`: más de 108 conexiones (el 80 % de las 135 de la capacidad máxima) durante 5 minutos |
| Objetivos de servicio | Latencia de la API (la capacidad), Disponibilidad de la API (las conexiones) |
| En el emulador | Solo en producción: la nube emulada usa el MySQL de Compose, y el stack database nunca se despliega. `make cloud-artisan ARGS="db:connections"` lee de la misma forma las conexiones de ese MySQL |

## Qué significa y a quién afecta

Aurora MySQL Serverless v2 escala entre 0,5 y 4 ACU. Su capacidad máxima fija su `max_connections`: 135 a 4 ACU (docs/es/infrastructure.md).

- **Cerca de la capacidad máxima**, todas las consultas se vuelven lentas. Primero la API se pone más lenta ([api-latency.md](api-latency.md)), y luego las peticiones agotan su tiempo de espera.
- **Cerca del límite de conexiones**, la base de datos rechaza las conexiones nuevas. Fallan las funciones que arrancan un entorno de ejecución nuevo, y también las funciones de rotación y cualquier operador que se conecte.

Las funciones pueden tener 60 conexiones como máximo (su concurrencia reservada). Más de 108 quiere decir que algo más tiene conexiones, o que las funciones se desplegaron sin concurrencia reservada.

## Confírmalo

1. **Quién tiene las conexiones**, por usuario (los dos usuarios alternos de la identidad de la aplicación, la identidad de migraciones, RDS Proxy), y cuántas están inactivas.
   - Emulador: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
2. **La capacidad y las conexiones de las últimas horas.**
   - Emulador: ninguno (solo producción).
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name ServerlessDatabaseCapacity --dimensions Name=DBClusterIdentifier,Value=dian-einvoice-prod-database --start-time <ISO time of 3 hours ago> --end-time <ISO time now> --period 300 --statistics Maximum`, el mismo comando con `--metric-name DatabaseConnections`, y los widgets "Database" del tablero.
3. **El estado actual del clúster**: su rango de capacidad y sus instancias.
   - Emulador: ninguno (solo producción).
   - AWS: `aws rds describe-db-clusters --db-cluster-identifier dian-einvoice-prod-database --query "DBClusters[].[Status,ServerlessV2ScalingConfiguration,DBClusterMembers]"`
4. **Las consultas lentas** (de más de un segundo), en el grupo de registros adonde llega el registro de consultas lentas del clúster, que se conserva un año.

## Causas probables

- **Más tráfico**, y la capacidad lo sigue: un crecimiento legítimo, o una integración que emite en masa.
- **Una consulta lenta** de una versión nueva, o un índice que falta, que retiene las conexiones por más tiempo.
- **Conexiones que se quedan abiertas**: la sesión de un operador, o un proceso por fuera de las funciones.
- **Las funciones desplegadas sin concurrencia reservada** (`reservedConcurrency: unreserved`): entonces la API puede abrir tantas conexiones como permita la cuota de la cuenta.
- **La depuración de cada hora** de los datos viejos, una consulta pesada por un momento.

## Responde

5. **Una versión nueva con una consulta lenta**: revierte a la imagen anterior ([api-errors.md](api-errors.md), paso 4).
6. **Conexiones que no son de las funciones**, como la sesión de un operador: termínalas desde una sesión con la identidad de migraciones.
7. **Un crecimiento que se mantiene**: sube la capacidad máxima (`maxCapacity`, en `lib/stage.ts` de la app de CDK). A 8 ACU, el límite es de 1.000 conexiones. El `max_connections` más alto se aplica cuando las instancias se reinician, lo que implica un failover a la instancia de lectura. O, cuando la concurrencia reservada de las funciones tenga que superar `max_connections`, activa RDS Proxy (`proxy: true`). Valida el cambio sin conexión, y luego despliega el stack database con el flujo de despliegue.
   - Emulador: `make cdk-check` (el stack database se valida, pero nunca se despliega en el emulador)
   - AWS: el flujo de despliegue, corrido desde el commit con el cambio; primero muestra las diferencias del stack database.
8. **Vuelve a poner la concurrencia reservada** en cuanto la cuota de la cuenta lo permita (docs/es/deployment.md).

## Verifica la recuperación

9. **Las conexiones y la capacidad están por debajo de sus umbrales**, y las alarmas vuelven a `OK`.
   - Emulador: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-database-capacity dian-einvoice-prod-database-connections --query "MetricAlarms[].[AlarmName,StateValue]"`

## Después

- Una capacidad máxima nueva implica un presupuesto de conexiones nuevo. La prueba que ata la concurrencia reservada de las funciones a `max_connections` (`infra/cdk/test/database.test.ts`) tiene que cambiar con ella.
- El costo de la capacidad: una hora a 4 ACU cuesta unas cuatro veces lo que una hora a 1 ACU.
