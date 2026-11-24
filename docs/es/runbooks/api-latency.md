# La API está lenta: `api-latency`

[English](../../runbooks/api-latency.md) · **Español**

<!-- Traducción de docs/runbooks/api-latency.md con SHA-256 a62c33a3aa275fc2aae134b148f0b3bcfab5f686a2715f5bf33e55f04120ea61. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `api-latency`: el p99 de la latencia de API Gateway pasa de 2 s durante 15 minutos |
| Objetivo de servicio | Latencia de la API: el 99 % de las peticiones a la API reciben una respuesta en 1 segundo o menos |
| En el emulador | Las métricas nativas de API Gateway no se publican; los tiempos de cada petición están en las líneas `http.request` de la función api |

## Qué significa y a quién afecta

Una de cada cien peticiones tarda más del doble del objetivo de servicio. La aplicación web se siente lenta. Las integraciones con un tiempo de espera corto pueden rendirse y reintentar; con su Idempotency-Key, los reintentos tienen efecto una sola vez.

La alarma espera a que la latencia doble el objetivo durante un cuarto de hora, así que una ráfaga de arranques en frío después de un despliegue no la dispara.

## Confírmalo

1. **Qué peticiones están lentas**: ruta, estado, tiempo y recorrido, primero las más lentas.
   - Emulador: `make cloud-errors FN=api MINUTES=30` para los errores, y `make cloud-follow ID=<correlation id of a slow request> HOURS=1` para una petición lenta
   - AWS: la consulta guardada `dian-einvoice/prod/slow-requests`
2. **En qué se va el tiempo**: en la función, o antes de ella, en API Gateway y en el verificador de origen. El tiempo de la función es el `duration_ms` de sus líneas `http.request`. El tiempo previo a la función se ve en el registro de acceso de API Gateway (`integrationLatency` y `authorizerLatency`, en `/aws/http-api/dian-einvoice-prod-functions`).
   - Emulador: `make cloud-functions`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.duration_ms > 2000 }'`. Una petición lenta que es rápida en la función esperó antes de llegar a ella.
3. **La base de datos**, la primera sospechosa cuando una petición es lenta.
   - Emulador: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`, y el widget "Database: capacity (ACU)" del tablero.

## Causas probables

- **La base de datos en su capacidad máxima** (4 ACU), o consultas lentas (mira su registro de consultas lentas, que se conserva un año): [database-capacity.md](database-capacity.md).
- **Arranques en frío**: muchos entornos de ejecución nuevos después de un despliegue o de una ráfaga de tráfico. Un arranque en frío tarda segundos la primera vez que AWS descarga una imagen nueva.
- **Una dependencia lenta**: S3, DynamoDB, SNS o KMS están lentos dentro de una petición. En producción, también la salida a internet por el NAT gateway, para los servicios sin un endpoint de gateway.
- **Throttling**: peticiones que esperan un entorno de ejecución: [function-throttles.md](function-throttles.md).
- **Una versión nueva** con una consulta más lenta o más trabajo por petición.

## Responde

4. **La base de datos**: sigue su runbook ([database-capacity.md](database-capacity.md)).
5. **Una versión nueva**: revierte a la imagen anterior.
   - Emulador: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`
6. **Arranques en frío que se repiten** (tráfico que va y viene): dale más memoria a la función api (`memorySize` en el servicio serverless). Más memoria trae más CPU y arranques en frío más cortos. Despliega el cambio con el flujo de despliegue.

## Verifica la recuperación

7. **Las peticiones lentas ya no aparecen** en la consulta guardada de los últimos 15 minutos, y la alarma vuelve a `OK` después de sus tres periodos.
   - Emulador: `make cloud-alarms`
   - AWS: la consulta guardada `dian-einvoice/prod/slow-requests`, luego `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-latency --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Compara el percentil de 30 días con el objetivo de servicio, en el widget "API latency (ms)" del tablero.
- Si encontraste una consulta lenta, agrega su índice en una migración que solo agregue (`make migrations-check`).
