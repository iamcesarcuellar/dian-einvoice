# Los eventos esperan en el outbox, o el relay se detiene: `relay-not-running`, `outbox-lag`

[English](../../runbooks/outbox-relay.md) · **Español**

<!-- Traducción de docs/runbooks/outbox-relay.md con SHA-256 ebd14607cdd7c3e7a56fa3cc8fe2b34d67798a6de2ecd690564334d3280a33dd. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarmas | `relay-not-running`: ninguna ejecución del relay en 3 minutos (la falta de datos cuenta como incumplimiento del umbral); `outbox-lag`: un evento sin publicar durante más de 2 minutos, a lo largo de 2 ejecuciones del relay |
| Objetivo de servicio | Tiempo hasta la respuesta de la DIAN |
| En el emulador | Las dos alarmas leen filtros de métricas sobre los registros del relay, que el emulador evalúa de verdad. |

## Qué significa y a quién afecta

Cada cambio guarda sus eventos en la tabla del outbox, en la misma transacción del cambio. El proceso que escribe el cambio los publica en el tema justo después de su commit (el camino rápido). Una vez por minuto, la función relay publica lo que haya quedado pendiente. Mira en docs/es/infrastructure.md cómo funciona el outbox sin un relay que corra todo el tiempo.

Cuando el relay no corre, o los eventos esperan aunque corra, no ocurren los pasos que esos eventos ponen en marcha:

- Una factura emitida queda numerada y firmada, pero nunca se transmite.
- Una entrega nunca empieza.
- Un webhook nunca se envía.

Las empresas ven sus documentos detenidos en un estado, sin ningún error. No se pierde nada: los eventos se quedan en la tabla hasta que se publican.

- `relay-not-running` sola: el camino rápido sigue publicando casi todo. Solo espera lo que algún proceso dejó sin publicar.
- `outbox-lag`: de verdad hay eventos esperando, con relay o sin él. O el tema los rechaza, o el relay no da abasto.

## Confírmalo

1. **El estado del outbox**: cuántos eventos esperan y de qué tipos, cuánto lleva esperando el más antiguo, qué se publicó hace poco y cuándo corrió el relay por última vez.
   - Emulador: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
2. **La programación y la función del relay.**
   - Emulador: `make cloud-functions`
   - AWS: `aws scheduler get-schedule --name dian-einvoice-prod-relay --query "[State,ScheduleExpression]"`, y `aws lambda get-function --function-name dian-einvoice-prod-relay --query "[Configuration.State,Configuration.LastUpdateStatus,Concurrency]"`
3. **Sus errores**: una ejecución que falla registra su error, y una que nunca arranca no registra nada.
   - Emulador: `make cloud-errors FN=relay MINUTES=30`
   - AWS: la consulta guardada `dian-einvoice/prod/errors-by-function`, y `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-relay --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "outbox.relay_run" }'` para sus últimas ejecuciones.

## Causas probables

| Lo que ves | Causa |
| --- | --- |
| Ninguna ejecución en sus registros; la programación está en `DISABLED` o no existe | Se apagó o se borró la programación por fuera de CloudFormation, o el rol de las programaciones perdió su permiso |
| Ejecuciones que fallan: la base de datos (conexiones, credenciales) | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| Ejecuciones que fallan: SNS rechaza la publicación (`AuthorizationError`, KMS) | Cambió el tema, su política o la política administrada `publish-document-events` |
| Ejecuciones que terminan bien, y el retraso crece | Muchos eventos a la vez, porque el relay publica durante 40 s por ejecución. O el camino rápido falla para todos los procesos que escriben (el mismo rechazo de SNS) |
| Throttling del relay | Una ejecución que se cuelga ocupa la concurrencia reservada del relay (1): [function-throttles.md](function-throttles.md) |
| `paused by the demo seeder: yes` | Solo en la nube emulada: `make cloud-demo` retiene el relay hasta que termina |

## Responde

4. **Publica ya lo que espera**, a mano. Corre el comando del propio relay en la función de consola, que tiene permiso para publicar en el tema. Repítelo mientras dure el retraso.
   - Emulador: `make cloud-artisan ARGS="outbox:publish-pending"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-relay --cli-binary-format raw-in-base64-out --payload '"outbox:publish-pending"' /dev/stdout` (la propia función relay), o la función de consola con el mismo payload.
5. **Deja el relay como lo declara su plantilla.** Desplegar las funciones con la imagen que corren restablece una programación o una función que se cambió por fuera de CloudFormation.
   - Emulador: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`, o el flujo de despliegue.
6. **SNS rechaza**: la causa está en el stack messaging o en su llave. Despliega ese stack desde el último commit desplegado con el flujo de despliegue, que muestra primero las diferencias.

## Verifica la recuperación

7. **Nada espera, y el relay corre una vez por minuto.**
   - Emulador: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
8. **Las cadenas avanzaron**: nada se queda detenido en la cadena más allá de sus reintentos.
   - Emulador: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **Las alarmas vuelven a `OK`**: `relay-not-running` después de una ejecución, y `outbox-lag` después de dos ejecuciones sin retraso.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-relay-not-running dian-einvoice-prod-outbox-lag --query "MetricAlarms[].[AlarmName,StateValue]"`

## Después

- Cuánto esperaron los eventos y de qué documentos. Los que se quedaron detenidos más de 5 minutos cuentan en contra del objetivo de servicio.
- Una programación apagada a mano es una desviación: solo CloudFormation debe cambiarla.
- Si `RelayPublishedEvents` está por encima de cero casi todos los minutos, el camino rápido está fallando. Averigua por qué: solo con el relay, cada paso tarda hasta un minuto más.
