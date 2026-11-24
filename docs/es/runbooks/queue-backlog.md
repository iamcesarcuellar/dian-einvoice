# Una cola que deja de avanzar: `chain-queue-age`, `webhooks-queue-age`

[English](../../runbooks/queue-backlog.md) · **Español**

<!-- Traducción de docs/runbooks/queue-backlog.md con SHA-256 7fcabae6a77f9580da63255f6170876911997e90554d6f3cf526a4c987cb811f. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarmas | `chain-queue-age`, `webhooks-queue-age`: el mensaje más antiguo de la cola tiene más de 10 minutos, durante 5 minutos |
| Objetivo de servicio | Tiempo hasta la respuesta de la DIAN (la cadena); los webhooks no tienen uno propio |

## Qué significa y a quién afecta

Un mensaje lleva esperando en `dian-einvoice-prod-chain` o en `dian-einvoice-prod-webhooks` más de lo que espera cualquier reintento de sus trabajos: su función consumidora no lo toma. La espera más larga entre reintentos es de unos 144 s, tanto en la cadena como para un webhook.

- En la cadena, los documentos se detienen entre dos pasos: emitidos pero no transmitidos, o aceptados pero no entregados.
- En la cola de los webhooks, las empresas dejan de recibir sus eventos.

No se pierde nada mientras la cola guarde el mensaje (4 días). Cuando un mensaje agota las recepciones que permite su política de redrive, va a la cola de mensajes fallidos ([dead-letters.md](dead-letters.md)).

## Confírmalo

Los comandos de AWS de abajo nombran los recursos de la cadena. Para `webhooks-queue-age`, usa `dian-einvoice-prod-webhooks` para la función y la cola, y `dian-einvoice-prod-webhooks-queue-age` para la alarma.

1. **El mapeo de la cola y su función**: el mapeo debe estar en `Enabled`, y la función en `Active`.
   - Emulador: `make cloud-functions`
   - AWS: `aws lambda list-event-source-mappings --function-name dian-einvoice-prod-chain --query "EventSourceMappings[].[State,StateTransitionReason,LastProcessingResult]"`, y `aws lambda get-function --function-name dian-einvoice-prod-chain --query "[Configuration.State,Concurrency]"`
2. **Cuántos mensajes tiene la cola y qué antigüedad tienen.**
   - Emulador: `make cloud-artisan ARGS="chain:status"` (lo que tiene la cadena), y la cola en la consola web del emulador
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible ApproximateNumberOfMessagesDelayed`
3. **Los errores y el throttling del consumidor**: un consumidor que se cae en cada lote lo devuelve todo.
   - Emulador: `make cloud-errors FN=chain MINUTES=30`
   - AWS: la consulta guardada `dian-einvoice/prod/errors-by-function`, y el widget "Function errors and throttles" del tablero.

## Causas probables

- **El mapeo está desactivado** (`Disabled`, `Disabling`), a mano o por Lambda: se borró su función o su rol, o se perdió un permiso.
- **El consumidor falla en cada lote**: un error antes de que corra cualquier trabajo (el arranque de la aplicación, sus secretos, la base de datos): [function-errors.md](function-errors.md).
- **Lambda le aplica throttling al consumidor**: por su concurrencia reservada (chain 10, webhooks 5) o por la cuota de la cuenta: [function-throttles.md](function-throttles.md).
- **Trabajos que tardan demasiado**: un paso que se acerca a su tiempo de espera (35 s) se devuelve antes del plazo del lote, una y otra vez. El widget "Chain job duration, p95 (ms)" del tablero muestra qué paso.
- **No es una falla**: un reintento programado para mucho más tarde espera invisible, como debe ser. No sube la antigüedad del mensaje visible más antiguo.

## Responde

4. **Deja el mapeo como lo declara su plantilla**, desplegando las funciones con la imagen que corren.
   - Emulador: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`
5. **Un consumidor que falla o tiene throttling**: sigue primero su runbook ([function-errors.md](function-errors.md), [function-throttles.md](function-throttles.md)). Si el problema empezó después de un despliegue, revierte a la imagen anterior.
   - Emulador: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`

## Verifica la recuperación

6. **La cola se vacía**: su mensaje más antiguo tiene menos de un minuto y los documentos avanzan.
   - Emulador: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages`
7. **La alarma vuelve a `OK`** después de 5 minutos por debajo del umbral.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-queue-age --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Los documentos que esperaron más de 5 minutos cuentan en contra del objetivo de servicio.
- Un mapeo desactivado a mano es una desviación. Cuando Lambda desactiva un mapeo, explica el motivo en `StateTransitionReason`: corrige esa causa en el código.
