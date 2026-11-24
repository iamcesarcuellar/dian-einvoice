# Funciones que fallan: `function-errors`

[English](../../runbooks/function-errors.md) · **Español**

<!-- Traducción de docs/runbooks/function-errors.md con SHA-256 a68141f844f1f4f29fd961f2a6a7ad4bc598ca49ff27b3d030ebbb50b7268a5d. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `function-errors`: una o más invocaciones fallidas de cualquier función en 5 minutos (métricas calculadas sobre la métrica `Errors` de cada función) |
| Objetivo de servicio | Disponibilidad de la API |
| En el emulador | El emulador no evalúa ni las métricas calculadas ni las métricas nativas de Lambda (docs/es/limitations.md). Los errores se ven en los registros, que lee `make cloud-errors`. |

## Qué significa y a quién afecta

Una invocación de una función falló por completo:

- una caída o un tiempo de espera agotado;
- un error mientras arranca un entorno de ejecución;
- un comando del relay, del programador o de la consola que terminó con un error.

Las fallas parciales de un lote (un mensaje de diez) y las respuestas de la API con un 4xx o un 5xx no son invocaciones fallidas, así que no cuentan aquí. A quién afecta depende de la función:

| Función | Cuando falla |
| --- | --- |
| `api` | Las peticiones que atiende ese entorno reciben un 502 o un 500 a través del borde, así que la aplicación web y las integraciones ven errores ([api-errors.md](api-errors.md)). |
| `chain`, `webhooks` | El lote entero vuelve a la cola después del tiempo de visibilidad (360 s en producción) y se reintenta. Después de 10 recepciones, sus mensajes van a la cola de mensajes fallidos. |
| `relay`, `scheduler` | La ejecución de ese minuto no hace nada, y la del minuto siguiente es su reintento ([outbox-relay.md](outbox-relay.md)). |
| `console` | La operación que corría falla, con su salida. |
| `origin-verifier` | API Gateway responde 500 a las peticiones cuyo valor de `X-Origin-Verify` no pudo revisar el verificador ([origin-verifier.md](origin-verifier.md)). |
| `notifier` | Lambda reintenta la invocación dos veces y luego la manda a su cola de mensajes fallidos ([notifier.md](notifier.md)). |

## Confírmalo

1. **Qué función falló y qué registró.**
   - Emulador: `make cloud-errors MINUTES=30` (o `FN=<function>`)
   - AWS: la consulta guardada `dian-einvoice/prod/errors-by-function` en los últimos 30 minutos, luego `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-<function> --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.level = "error" || $.level = "critical" }'`
2. **Las invocaciones fallidas por función**, y si las fallas empezaron con un despliegue.
   - Emulador: `make cloud-deploy STEP=versions` (cuándo cambió cada función por última vez)
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Errors --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, y `sh scripts/deploy.sh prod versions`
3. **Sigue una falla** por el id de correlación de su línea de registro.
   - Emulador: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: la consulta guardada `dian-einvoice/prod/follow-a-journey`.

## Causas probables

- **Una versión nueva**: los errores empiezan en el minuto en que termina un despliegue, con la misma clase de error en cada línea.
- **El arranque del entorno**: un entorno nuevo no puede leer sus secretos (no hay conexión con Secrets Manager, o se perdió un permiso) o no llega a la base de datos. Cada entorno nuevo falla, mientras los que ya están en caliente siguen atendiendo.
- **La base de datos**: conexiones rechazadas, demasiadas conexiones, un failover ([database-capacity.md](database-capacity.md)).
- **Un tiempo de espera agotado**: un trabajo o una petición espera a un servicio que no responde. La DIAN tiene su propio tiempo de espera de 8 s, y S3, SQS, SNS, KMS y Secrets Manager tienen 10 s cada uno. En producción, la causa puede ser la salida a internet por el NAT gateway.
- **La memoria**: `Runtime exited` o `out of memory` en los registros del sistema de Lambda, por ejemplo mientras la cadena genera el PDF de una factura grande.

## Responde

4. **Una versión nueva: revierte a la imagen anterior**, identificada por su resumen. La base de datos se queda como está. Las migraciones solo agregan (make migrations-check), así que el código anterior sigue funcionando con ella.
   - Emulador: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, o el flujo de despliegue desde el commit anterior.
5. **Fallas de arranque o de la base de datos**: mira [rotate-secrets.md](rotate-secrets.md) para las credenciales y [database-capacity.md](database-capacity.md) para las conexiones.
6. **Los mensajes que fallaron mientras tanto** vuelven solos, después del tiempo de visibilidad. Los que agotaron sus recepciones están en la cola de mensajes fallidos: devuélvelos a su cola cuando hayas corregido la causa ([dead-letters.md](dead-letters.md)).

## Verifica la recuperación

7. **Ninguna línea de error nueva** en los minutos siguientes, y la API responde a través del borde.
   - Emulador: `make cloud-errors MINUTES=10`, luego `make cloud-deploy STEP=smoke`
   - AWS: la consulta guardada `dian-einvoice/prod/errors-by-function` en los últimos 10 minutos, luego `sh scripts/deploy.sh prod smoke`
8. **La alarma vuelve a `OK`** después de un periodo de 5 minutos sin errores.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Una versión que falló: agrega la prueba que la habría detectado, antes de la siguiente versión.
- Cuántas peticiones fallaron: se descuentan del presupuesto de errores de la API, de 43 minutos al mes.
