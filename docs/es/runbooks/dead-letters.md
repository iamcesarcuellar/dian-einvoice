# Mensajes fallidos: `chain-dead-letters`, `webhooks-dead-letters`

[English](../../runbooks/dead-letters.md) · **Español**

<!-- Traducción de docs/runbooks/dead-letters.md con SHA-256 913ee4e00b90920a5c688801ba554373d0839f9d45380c8c78eed6e589b6cd54. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarmas | `chain-dead-letters`, `webhooks-dead-letters`: un mensaje en la cola de mensajes fallidos de su cola durante 1 minuto |
| Objetivo de servicio | Tiempo hasta la respuesta de la DIAN (la cadena); los webhooks no tienen uno propio |
| Simulacro | `make cloud-drill` corre este runbook de punta a punta ([drill-report.md](drill-report.md)) |

## Qué significa y a quién afecta

Trabajo que no se va a hacer solo. Un mensaje llega a la cola de mensajes fallidos (dead-letter queue) `dian-einvoice-prod-chain-dlq` o `dian-einvoice-prod-webhooks-dlq` de una de tres formas:

- **Un paso se rindió para siempre.** Un paso de la cadena o de la entrega se quedó sin intentos. Por ejemplo, una transmisión a la que la DIAN nunca respondió después de un primer intento y cinco reintentos (unos tres minutos), o un correo que el servidor de correo siguió rechazando. El paso deja su documento fallido y pone una copia de su mensaje en la cola de mensajes fallidos. El motivo está en el atributo `dead_letter_reason` (evento de registro `queue.dead_letter`).
- **Un trabajo siguió lanzando excepciones.** Un trabajo falló con un error inesperado en cada una de sus 10 recepciones (la base de datos, el almacenamiento, un bug). SQS mueve el mensaje según la política de redrive de la cola, y el documento queda fallido en su paso.
- **SNS no pudo entregar un evento** a la cola, o un mensaje no se puede leer (no hay un handler para su tipo).

A quién afecta: a las empresas cuyos documentos llevan esos mensajes.

- **La cadena**: la empresa de cada documento lo ve **fallido** en su paso, con el motivo, en la aplicación web y por la API (`electronic_status` `failed`, `electronic_failed_step` `transmit`). La empresa también recibe el webhook de la falla. La DIAN no ha validado el documento, así que no se entregó.
- **Los webhooks**: a una empresa no le llega un evento a su endpoint.

No se pierde nada. La cola de mensajes fallidos guarda cada mensaje 14 días, y devolver un mensaje a su cola reintenta su paso.

## Confírmalo

1. **Mira los mensajes sin consumirlos**: cuándo llegó cada uno, cuántas veces se recibió, su tipo, su empresa, su documento y su recorrido, y por qué se rindió su trabajo.
   - Emulador: `make cloud-dead-letters QUEUE=chain` (o `QUEUE=webhooks`)
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All --message-attribute-names All`. Con un tiempo de visibilidad de 0, no se consume nada; repite el comando para ver más de 10. Lee los atributos, no el cuerpo, que lleva el evento.
2. **Sigue el recorrido de un mensaje** por su id de correlación (el atributo `correlation_id`): cada línea de su petición y de sus trabajos, y el motivo por el que se rindió su paso.
   - Emulador: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: la consulta guardada `dian-einvoice/prod/follow-a-journey` en CloudWatch Logs Insights, con el id en lugar de `REPLACE-WITH-THE-CORRELATION-ID`, sobre las horas que han pasado desde que llegó el mensaje.
3. **Mira los documentos que la cadena dejó fallidos**, por paso y motivo, y las últimas llamadas a la DIAN.
   - Emulador: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`

## Causas probables

| Qué dicen los motivos | Causa | Ve a |
| --- | --- | --- |
| El `Attempt 6 of 6` de una transmisión, con `server_error`, `timeout` o `refused` | La DIAN, o la salida hacia ella, estuvo caída más tiempo del que duran los reintentos de una transmisión | [dian-errors.md](dian-errors.md), luego el paso 5 de este runbook |
| Un correo con `Failed` en su último intento | El servicio de correo (SES), o la salida hacia él | [function-errors.md](function-errors.md), luego el paso 5 |
| `Unexpected error` y una excepción, recibido 10 o más veces | La base de datos, el almacenamiento, KMS, o un bug de una versión nueva | [function-errors.md](function-errors.md), [database-capacity.md](database-capacity.md); una versión nueva: [api-errors.md](api-errors.md), paso 4 |
| Un mensaje sin `dead_letter_reason`, recibido una vez o nunca | SNS no pudo entregar un evento a la cola (la política de la cola, KMS) | la política de la cola, en el stack messaging; luego el paso 5 |
| `jobs of the chain queue take events of type ...` | Un tipo de evento al que no se suscribe ningún trabajo, o más de uno: una versión nueva enruta un evento que el consumidor no conoce | las rutas de `backend/resources/outbox/routes.json`, y los suscriptores; luego el paso 5 |

## Responde

4. **Primero corrige la causa**, con el runbook que nombra la tabla. Los mensajes que se devuelven mientras dura la causa vuelven directo aquí: una transmisión se rendiría otra vez después de sus reintentos.
5. **Devuelve los mensajes a su cola** con el redrive nativo de SQS (una tarea de movimiento de mensajes). Cada paso vuelve a empezar donde se rindió. Un documento que falló en un paso de esa ejecución empieza una ejecución nueva desde ese paso, como lo hace la acción Reintentar ahora de la aplicación web. Un mensaje de un documento que un usuario ya reintentó, o un mensaje que vuelve dos veces, no hace nada (mira la sección de mensajes fallidos de docs/es/infrastructure.md).
   - Emulador: `make cloud-redrive QUEUE=chain` (o `QUEUE=webhooks`)
   - AWS: `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`, luego `aws sqs list-message-move-tasks --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq` hasta que su estado sea `COMPLETED`. El destino siempre es la cola del canal, así que la tarea mueve las dos clases de mensajes: los que movió la política de redrive, y los que un trabajo copió ahí cuando se rindió. Para moverlos despacio, agrega `--max-number-of-messages-per-second 10`.

   **Reintentar ahora y el redrive, en cualquier orden.** Una factura fallida tiene dos caminos de vuelta. Su empresa puede presionar Reintentar ahora (en la aplicación web, o con `POST /api/v1/invoices/{id}/retry` por la API), y el operador puede devolver su mensaje fallido a la cola. El que llegue primero recupera la factura, y el otro no hace nada. Por eso los operadores devuelven los mensajes fallidos sin preguntarles antes a las empresas, y una empresa puede presionar Reintentar ahora en cualquier momento:
   - **Primero Reintentar ahora**: la factura empieza una ejecución nueva. Su mensaje fallido, devuelto después, es de una ejecución ya reintentada y no hace nada.
   - **Primero el redrive**: el paso empieza una ejecución nueva. Un Reintentar ahora posterior se rechaza (409) una vez la factura queda aceptada. Mientras la ejecución nueva espera un reintento, Reintentar ahora solo adelanta ese reintento, como siempre.
   - **Los dos al mismo tiempo**: cada uno comprueba, con el bloqueo de la factura, que la factura sigue fallida en esa ejecución, así que solo uno de los dos empieza una ejecución.

   De cualquier forma, la factura va a la DIAN una vez más, su cliente recibe un solo correo, y cada suscripción recibe un solo webhook por evento. Nada más cambia en la factura (`backend/tests/Integration/Dian/RecoveryTest.php`).

   Un correo que se rindió se comporta igual entre Enviar de nuevo y su mensaje fallido, con una diferencia. Enviar de nuevo, en una factura que ya se entregó, la envía otra vez a propósito. Así que, cuando el redrive entregue una factura, avísale a su empresa que se entregó.

   Un mensaje fallido conserva el recorrido de su factura. Una vez devuelto, su paso sigue con el mismo id de correlación. Así, el paso 2 cuenta la falla, el mensaje fallido, el paso reanudado y la recuperación como una sola historia. Un Reintentar ahora es un recorrido propio: la petición de la empresa. La línea de tiempo de la factura, en la aplicación web, muestra los dos.
6. **Un mensaje que no puede volver a correr**: un bug que ninguna versión ha corregido todavía, o un evento de un tipo que nadie atiende. Déjalo en la cola de mensajes fallidos, que lo guarda 14 días, hasta que se despliegue la versión que lo corrige. Mientras tanto, la alarma sigue en `ALARM`, así que dilo en el chat.

## Verifica la recuperación

7. **La cola de mensajes fallidos está vacía**, y la cola se va desocupando.
   - Emulador: `make cloud-dead-letters QUEUE=chain`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible`
8. **Los documentos pasaron**: el incidente no dejó ninguno fallido, y las llamadas a la DIAN reciben respuesta.
   - Emulador: `make cloud-artisan ARGS="chain:status"`; un documento: `make cloud-artisan ARGS="chain:status --document=<id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **La alarma vuelve sola a `OK`** en pocos minutos, y el chat lo dice.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[StateValue,StateUpdatedTimestamp]"`

## Después

- Cuántos documentos esperaron, de cuántas empresas y por cuánto tiempo. Puede que haya que hablar con las empresas cuyas facturas esperaron, porque sus clientes esperaron esas facturas.
- Si los reintentos de la cadena alcanzaron para la caída. Si la DIAN se cae a menudo por más de tres minutos, haz que la cadena reintente durante más tiempo antes de rendirse (`DIAN_RETRY_DELAYS`, un parámetro de etapa de las funciones).
- Si la causa fue una versión nueva: qué prueba la habría detectado.
- Escribe una revisión sin culpables, como la de [drill-report.md](drill-report.md).
