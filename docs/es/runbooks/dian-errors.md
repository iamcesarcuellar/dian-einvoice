# La DIAN falla: `dian-error-rate`

[English](../../runbooks/dian-errors.md) · **Español**

<!-- Traducción de docs/runbooks/dian-errors.md con SHA-256 44cbeefe362fea712c5d3b3a9d34e0845817b9385e003e4e2a90e0be77fcbd63. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `dian-error-rate`: durante 10 minutos, más del 20 % de las llamadas a la DIAN en 5 minutos no obtienen un resultado de validación, con 5 llamadas o más. Usa métricas calculadas sobre `DianCalls` y `DianErrors` |
| Objetivo de servicio | Tiempo hasta la respuesta de la DIAN (los minutos en que la propia DIAN está caída no cuentan en su contra) |
| En el emulador | Las métricas calculadas no se evalúan (docs/es/limitations.md). La caída aparece como mensajes fallidos de la cadena una vez las transmisiones se rinden, y `make cloud-drill` lo ensaya |

## Qué significa y a quién afecta

El servicio web de la DIAN, o la salida de la plataforma hacia él (el NAT gateway en producción), no responde. Las llamadas terminan en tiempos de espera agotados, conexiones rechazadas, HTTP 5xx o respuestas que no vienen del servicio. Emitir sigue funcionando. La API numera cada factura, le da su CUFE y responde de inmediato, porque firmar y transmitir son pasos posteriores de la cadena. Lo que pasa después:

| | |
| --- | --- |
| Lo que espera | Cada transmisión en `sending`, con su próximo intento programado. No se pierde nada: cada paso pendiente es un evento del outbox o un mensaje en la cola `chain` |
| Lo que se reintenta | Un primer intento y cinco reintentos, con 2, 5, 15, 45 y 120 s entre uno y otro, más una variación aleatoria (`DIAN_RETRY_DELAYS`; la etapa local reintenta dos veces, a los 2 y a los 5 s). Cuando una llamada pudo haber llegado, la transmisión pregunta con GetStatus antes de volver a enviar. La DIAN responde a un número repetido con la regla 90, así que nada se valida dos veces |
| Lo que se rinde | Después del último reintento (unos tres minutos), el documento queda fallido en `transmit`. Una copia de su mensaje va a la cola de mensajes fallidos de la cadena, donde salta `chain-dead-letters` ([dead-letters.md](dead-letters.md)) |
| Lo que ve la empresa | La factura emitida, con su número y su CUFE, en `sending` y con la hora de su próximo intento. Después del último intento: `failed` en `transmit`, con el motivo (sin respuesta de la DIAN después de 6 intentos), y el webhook de la falla |

La DIAN no ha validado la factura, así que no se le entrega a su cliente: el correo espera la validación. La empresa puede presionar Reintentar ahora (`POST /api/v1/invoices/{id}/retry`) en cualquier momento. Cuando la DIAN vuelva a responder, los operadores reintentan todas las facturas a la vez (paso 5).

## Confírmalo

1. **¿La DIAN responde ahora?** La sonda consulta con GetStatus un documento que no puede existir, lo que no cambia nada. Termina con el código 1 cuando la DIAN no responde.
   - Emulador: `make cloud-artisan ARGS="dian:probe"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`. Revisa también los canales de estado de la propia DIAN.
2. **Cómo terminaron las llamadas de los últimos minutos**, y qué espera un reintento o ya se rindió.
   - Emulador: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=30"' /dev/stdout`, y el widget "DIAN calls, errors and latency" del tablero.
3. **Sigue una transmisión** por un id de correlación: el de la respuesta a la emisión de su factura, o el de una línea de registro que nombre su documento.
   - Emulador: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: la consulta guardada `dian-einvoice/prod/follow-a-journey`: las líneas `dian.call`, con su resultado y su estado HTTP.

## Causas probables

- **La DIAN está caída o degradada**: todas las empresas fallan a la vez, en todas las operaciones, y la sonda falla. No hay nada que corregir de nuestro lado: espera, y mantén informadas a las empresas.
- **La salida de la plataforma** (en producción: el NAT gateway o su zona): los webhooks y SES también fallan (`webhook-failures`, correos que fallan), y la DIAN responde desde otros lugares. Se recupera sola cuando vuelve la salida (mira la tabla de fallas de docs/es/infrastructure.md). Un segundo NAT gateway (`natGateways: 2`) elimina esta causa para la próxima vez.
- **El certificado o el endpoint** (en producción): el TLS hacia la DIAN, una URL que cambió en `DIAN_SOAP_URL`, o una lista de direcciones permitidas de la DIAN sin las direcciones fijas de `/dian-einvoice/prod/network/egress-addresses`. Las llamadas fallan desde el primer despliegue, con `client_error` o `refused`.
- **Una regla de la DIAN rechaza documentos**: eso no dispara esta alarma, porque un rechazo es una respuesta. Mira `DocumentsRejected` y la consulta guardada `dian-einvoice/prod/dian-rejections-by-rule`.

## Responde

4. **Avísales a las empresas** que la DIAN está caída y que sus facturas se validarán cuando vuelva; no tienen que hacer nada. Los minutos en que la DIAN está caída no cuentan en contra del objetivo de servicio.
5. **Cuando la sonda vuelva a pasar**, devuelve a la cadena las transmisiones que se rindieron. Cada una empieza una ejecución nueva de su transmisión ([dead-letters.md](dead-letters.md), paso 5).
   - Emulador: `make cloud-artisan ARGS="dian:probe"`, luego `make cloud-redrive QUEUE=chain`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`, luego `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`
6. **La salida** (en producción): revisa el NAT gateway y su zona en la consola de la VPC, y el widget "Outbound traffic: bytes to the internet and port errors (NAT gateway)" del tablero.

## Verifica la recuperación

7. **Las llamadas reciben respuesta y nada espera**: ningún `server_error` en los últimos minutos, y nada fallido en `transmit` desde el redrive.
   - Emulador: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=10"' /dev/stdout`
8. **Las alarmas vuelven a `OK`**: `dian-error-rate` después de dos periodos de 5 minutos con respuestas, y `chain-dead-letters` cuando su cola queda vacía.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-dian-error-rate dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[AlarmName,StateValue]"`

## Después

- Cuánto tiempo estuvo caída la DIAN, y cuántos documentos de cuántas empresas esperaron. El objetivo de servicio cuenta los documentos sin respuesta de la DIAN en 5 minutos, sin contar los minutos de la caída.
- Si los cinco reintentos alcanzaron. Duran unos tres minutos; las caídas más largas terminan en la cola de mensajes fallidos y necesitan el paso 5 cada vez.
- La DIAN tiene un procedimiento de contingencia para caídas largas (facturas de contingencia, emitidas sin su validación y enviadas después). Esta plataforma no lo implementa (docs/es/limitations.md).
