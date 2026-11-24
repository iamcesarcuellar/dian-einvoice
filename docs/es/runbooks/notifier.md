# El notificador falla: `notifier-failures`

[English](../../runbooks/notifier.md) · **Español**

<!-- Traducción de docs/runbooks/notifier.md con SHA-256 c5c23da43b6f5ba7d93d9edb572871cb9aa783d67dd6fdb131fd5be80b908d8a. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `notifier-failures`: una notificación en la cola de mensajes fallidos del notificador (1 minuto). Esta alarma también va al tema de respaldo, cuyo correo no depende del notificador. |
| Objetivo de servicio | ninguno directamente: hace que todas las demás alarmas sigan llegando a los operadores |
| En el emulador | `make cloud-alarm-check` la corre de verdad. El receptor local de webhooks rechaza una notificación, el notificador la guarda en su cola de mensajes fallidos y esta alarma llega al chat cuando el receptor vuelve a recibir mensajes. |

## Qué significa y a quién afecta

El notificador no pudo publicar en el webhook del chat el cambio de estado de una alarma después de tres intentos, o Lambda no pudo correrlo después de dos reintentos. Así que el chat se perdió ese cambio, y puede que los operadores no sepan que otra cosa está en `ALARM`.

Es la única alarma que también publica en el tema de respaldo (`dian-einvoice-prod-alarms-fallback`), cuya suscripción les envía un correo a los operadores, así que les llega sin pasar por el chat. No afecta directamente a ningún cliente, pero las alarmas que ocultó pueden afectarlos a todos.

## Confírmalo

1. **Lo que se perdió el chat**: la alarma y el estado que lleva cada notificación de la cola de mensajes fallidos del notificador.
   - Emulador: `make cloud-dead-letters QUEUE=notifier`
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All`
2. **El estado actual de cada alarma**, que importa más que las notificaciones perdidas.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]"`
3. **Por qué fallaron las publicaciones**: el código de estado que devolvió el chat, o el error de conexión.
   - Emulador: `make cloud-errors FN=notifier MINUTES=60`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-notifier --start-time <epoch ms of an hour ago> --filter-pattern '{ $.event = "alarm.notification" && $.outcome = "failure" }'`

## Causas probables

- **La dirección del webhook está revocada o es incorrecta**: el chat devuelve 404 o 403 a cada publicación. La dirección es una credencial que el chat emite y puede revocar.
- **El chat está caído**, o les aplica un límite de peticiones a las publicaciones (429).
- **El secreto que guarda la dirección no se puede leer**: se borró `dian-einvoice/prod/alerts/chat-webhook`, o cambió el rol del notificador.

## Responde

4. **Atiende primero cada alarma en `ALARM`** que viste en el paso 2, con su runbook: esas alarmas son lo que se perdió el chat.
5. **Una dirección nueva para el webhook**: pídele una al chat y guárdala en su secreto (mira "La dirección del webhook del chat" en [rotate-secrets.md](rotate-secrets.md)). El notificador la lee en 5 minutos o menos.
   - Emulador: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
6. **Vacía la cola de mensajes fallidos del notificador** cuando hayas leído sus notificaciones. No tienen a qué cola volver, y las alarmas del paso 2 muestran su estado actual.
   - Emulador: `make cloud-dead-letters-purge`
   - AWS: `aws sqs purge-queue --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)"`

## Verifica la recuperación

7. **Una notificación llega al chat**: fuerza un estado en una alarma que esté en `OK` y luego déjala como estaba. Avisa primero en el chat que es una prueba.
   - Emulador: `make cloud-alarms` muestra los últimos mensajes que recibió el chat; `make cloud-check` fuerza uno
   - AWS: `aws cloudwatch set-alarm-state --alarm-name dian-einvoice-prod-notifier-failures --state-value ALARM --state-reason "A test of the chat after a new address"`, luego lo mismo con `--state-value OK`
8. **La alarma vuelve a `OK`**, con su cola de mensajes fallidos vacía.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-notifier-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Si el correo de respaldo les llegó a los operadores: revisa que su suscripción esté confirmada (SNS le pide a la dirección que la confirme una vez).
- Cuánto tiempo estuvo ciego el chat y qué se perdió.
