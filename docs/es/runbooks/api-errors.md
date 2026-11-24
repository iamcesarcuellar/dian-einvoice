# La API devuelve errores: `api-server-errors`

[English](../../runbooks/api-errors.md) · **Español**

<!-- Traducción de docs/runbooks/api-errors.md con SHA-256 42b3162081ad1981feeb56753e1db39576b4083d8129395fcf3cd2c5b96e8dc8. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `api-server-errors`: 5 o más 5xx en API Gateway en 5 minutos |
| Objetivo de servicio | Disponibilidad de la API: el 99,9 % de las peticiones a la API no devuelven ningún 5xx, un presupuesto de errores de 43 minutos al mes |
| En el emulador | Las métricas nativas de API Gateway no se publican, así que la alarma no recibe datos; los errores se ven en los registros de la función api |

## Qué significa y a quién afecta

Las peticiones que pasan por el borde, desde la aplicación web y desde las integraciones, reciben un 5xx. Sus usuarios no pueden iniciar sesión, emitir ni descargar. Una integración que reintenta una emisión con su Idempotency-Key recibe la misma respuesta cada vez.

Cada respuesta de la API lleva `X-Correlation-Id`, y cada respuesta de error lleva `correlation_id`. Un cliente puede mandarle ese id a soporte ([customer-report.md](customer-report.md)).

## Confírmalo

1. **Qué rutas fallan, y con qué estado.** El registro de acceso de API Gateway tiene la ruta, el estado, la latencia y el error de integración de cada petición.
   - Emulador: `make cloud-errors FN=api MINUTES=30` (el emulador no escribe el registro de acceso de API Gateway)
   - AWS: `aws logs filter-log-events --log-group-name /aws/http-api/dian-einvoice-prod-functions --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.status = 5* }'`
2. **Qué registró la función** para esas peticiones, y cuándo empezó.
   - Emulador: `make cloud-errors FN=api MINUTES=30`
   - AWS: la consulta guardada `dian-einvoice/prod/errors-by-function`, luego `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.detail.status >= 500 }'`
3. **Si empezó después de un despliegue.**
   - Emulador: `make cloud-deploy STEP=versions`
   - AWS: `sh scripts/deploy.sh prod versions`, y las ejecuciones del flujo de despliegue.

## Causas probables

| Qué dice el registro de acceso | Causa | Ve a |
| --- | --- | --- |
| `integrationError`, estado 502 o 500, y errores en la función | La función se cayó o agotó su tiempo de espera (28 s) | [function-errors.md](function-errors.md) |
| Estado 503 o 429 en API Gateway, y nada en la función | La función llegó a su concurrencia reservada y Lambda le aplicó throttling | [function-throttles.md](function-throttles.md) |
| `authorizerError`, estado 500 | Falló el verificador de origen | [origin-verifier.md](origin-verifier.md) |
| Un 500 de la aplicación con un error de la base de datos | Las conexiones, la capacidad o las credenciales | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| Errores 500 de la aplicación a partir de cierto minuto, todos de la misma clase de error | Una versión nueva | paso 4 |

## Responde

4. **Una versión nueva: revierte a la imagen anterior**, por su resumen. La base de datos se queda como está, porque las migraciones solo van hacia adelante. Si la versión también cambió la aplicación web, reviértela.
   - Emulador: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`, y `make cloud-deploy STEP=rollback-panel`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, y `sh scripts/deploy.sh prod rollback-panel`
5. **Cualquier otra causa**: sigue su runbook, según la tabla.

## Verifica la recuperación

6. **La API responde a través del borde**, y API Gateway sigue rechazando las peticiones que se saltan el borde.
   - Emulador: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`
7. **Ningún 5xx en los minutos siguientes**, y la alarma vuelve a `OK`.
   - Emulador: `make cloud-errors FN=api MINUTES=10`, luego `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-server-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Descuenta las peticiones fallidas del presupuesto de errores del mes. El tablero las cuenta (los 5xx de API Gateway).
- Puede que los clientes de la API hayan reintentado con la misma Idempotency-Key peticiones hechas durante los errores. Cada una de esas peticiones tuvo efecto una sola vez.
