# Webhooks que fallan: `webhook-failures`

[English](../../runbooks/webhook-failures.md) · **Español**

<!-- Traducción de docs/runbooks/webhook-failures.md con SHA-256 6d8b973b05ac462a759508fcb10f1045dbd209b194b9361b6d72793f9a8cb4b6. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `webhook-failures`: 20 o más entregas fallidas de webhooks en 15 minutos (el filtro de métricas `WebhookDeliveriesFailed`) |
| Objetivo de servicio | ninguno propio: los webhooks se entregan al menos una vez y sin un orden particular |
| En el emulador | El filtro de métricas y su alarma corren de verdad. El receptor de webhooks del simulador falla cuando se le pide (`make api-check` y `make cloud-alarm-check` usan su modo de error). |

## Qué significa y a quién afecta

Los webhooks no llegan: los endpoints de las empresas no los reciben, o falla la salida de la plataforma hacia ellos. Cada entrega se intenta una vez y luego cinco veces más, con 2, 5, 15, 45 y 120 s entre un intento y otro. Después de eso, la entrega queda fallida y se guarda. Una suscripción que falla 20 entregas seguidas se desactiva (`disabled`).

Las integraciones de esas empresas dejan de enterarse de sus facturas (emitidas, aceptadas, rechazadas, pagadas). Las facturas en sí no se ven afectadas. Una suscripción que falla es problema de su empresa; muchas a la vez son problema de la plataforma.

## Confírmalo

1. **Qué suscripciones fallan**, por empresa y por el host de su URL (nunca su ruta), con cuántas fallas seguidas llevan y su última respuesta.
   - Emulador: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
2. **Qué registraron los intentos**: el código de estado de cada respuesta, o el error de conexión.
   - Emulador: `make cloud-errors FN=webhooks MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-webhooks --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "webhook.delivery" && $.outcome = "failure" }'`

## Causas probables

- **Un endpoint está caído, lento o devuelve 5xx**: una suscripción, una empresa, en `webhooks:status`.
- **Un endpoint rechaza** (4xx, un secreto equivocado de su lado, una URL que cambió): una suscripción, con el mismo código de estado en cada intento.
- **Muchos endpoints a la vez**: la salida de la plataforma (en producción, el NAT gateway), o el DNS. La DIAN y SES también fallan ([dian-errors.md](dian-errors.md)).
- **Un endpoint que no es https en el puerto 443** se rechaza antes de cada intento en producción (docs/es/limitations.md).
- **Una lista de direcciones permitidas de una empresa** sin las direcciones fijas de la plataforma (`/dian-einvoice/prod/network/egress-addresses`).

## Responde

3. **Una sola empresa**: dile qué suscripción falla, desde cuándo y qué devolvió su endpoint. No hay nada que cambiar en la plataforma. Cuando su endpoint funcione, la empresa entra a Desarrolladores en la aplicación web y:
   - envía una prueba desde el menú de la suscripción (Enviar evento de prueba);
   - vuelve a activar la suscripción en su formulario (Activo), si estaba desactivada;
   - reenvía lo que se perdió desde las Entregas de la suscripción (Reenviar, en cada entrega fallida).
4. **Muchas a la vez**: revisa la salida a internet ([dian-errors.md](dian-errors.md), paso 6). Las entregas que fallaron mientras tanto quedan fallidas: diles a las empresas que las reenvíen, o que esperen los eventos que siguen.
5. **Los mensajes de la cola de los webhooks que fallaron en cada recepción** están en la cola de mensajes fallidos de esa cola. Son trabajos que lanzaron una excepción, no entregas fallidas: sigue [dead-letters.md](dead-letters.md) con `QUEUE=webhooks`.

## Verifica la recuperación

6. **Las fallas paran**: no hay entregas fallidas nuevas, y las suscripciones vuelven a `active`.
   - Emulador: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
7. **La alarma vuelve a `OK`** cuando fallan menos de 20 entregas en 15 minutos.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-webhook-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Una empresa cuyo endpoint falla a menudo: un consejo sobre idempotencia y respuestas rápidas (la plataforma espera 5 s por intento).
- Muchas a la vez: con un solo NAT gateway, la salida de la plataforma es un punto único de falla (`natGateways: 2` agrega un segundo).
