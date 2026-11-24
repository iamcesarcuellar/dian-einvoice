# Runbooks

[English](../../runbooks/README.md) · **Español**

<!-- Traducción de docs/runbooks/README.md con SHA-256 3abcd915d97972afdf1c36777deafabe298b4d03d4db8e053994e676dfb65c75. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Esta carpeta tiene lo que hay que hacer cuando salta una alarma, y los procedimientos que un operador corre sin que salte ninguna. Cada alarma nombra su runbook en su descripción, así que la notificación de la alarma en el chat lo trae (docs/es/observability.md). Cada runbook cubre:

- qué significa la alarma y a quién afecta;
- cómo confirmarla;
- sus causas probables;
- la respuesta, paso a paso;
- cómo verificar la recuperación;
- qué revisar después.

Cada paso puede correr en dos lugares:

- **Emulador**: un objetivo de make contra la nube emulada (primero `make cloud-up`). Los comandos de operación de la aplicación corren en la función de consola a través de `make cloud-artisan ARGS="..."`.
- **AWS**: el comando para una cuenta real, que se corre con la AWS CLI y las credenciales de un operador en esa cuenta. Es uno de estos:
  - un comando de la AWS CLI;
  - una consulta guardada de CloudWatch Logs Insights (`dian-einvoice/prod/...`);
  - un comando de la función de consola: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"<command>"' /dev/stdout`. Su respuesta trae en `output` lo que imprimió el comando.

  `<account>` es el ID de 12 dígitos de la cuenta.

Unas pruebas comprueban estas páginas contra el código (`infra/cdk/test/runbooks.test.ts`, `infra/functions/test/runbooks.test.mjs`, `backend/tests/Integration/Operations/RunbooksTest.php`):

- cada alarma nombra un runbook que existe;
- cada runbook aparece en este índice;
- existe cada objetivo de make, paso de `scripts/deploy.sh`, comando de la aplicación y comando de la AWS CLI que citan las páginas.

Nada de esto se ha corrido contra una cuenta real. El emulador corre los objetivos de make, y `make cloud-drill` corre de punta a punta la respuesta de `dead-letters.md`.

## Alarmas

| Alarma | Runbook | Objetivos de servicio que defiende |
| --- | --- | --- |
| `chain-dead-letters` | [dead-letters.md](dead-letters.md) | Tiempo hasta la respuesta de la DIAN |
| `webhooks-dead-letters` | [dead-letters.md](dead-letters.md) | ninguno: los webhooks se entregan al menos una vez, sin un objetivo de servicio propio |
| `chain-queue-age` | [queue-backlog.md](queue-backlog.md) | Tiempo hasta la respuesta de la DIAN |
| `webhooks-queue-age` | [queue-backlog.md](queue-backlog.md) | ninguno: los webhooks se entregan al menos una vez, sin un objetivo de servicio propio |
| `function-errors` | [function-errors.md](function-errors.md) | Disponibilidad de la API |
| `function-throttles` | [function-throttles.md](function-throttles.md) | Disponibilidad de la API, Latencia de la API |
| `api-server-errors` | [api-errors.md](api-errors.md) | Disponibilidad de la API |
| `api-latency` | [api-latency.md](api-latency.md) | Latencia de la API |
| `relay-not-running` | [outbox-relay.md](outbox-relay.md) | Tiempo hasta la respuesta de la DIAN |
| `outbox-lag` | [outbox-relay.md](outbox-relay.md) | Tiempo hasta la respuesta de la DIAN |
| `dian-error-rate` | [dian-errors.md](dian-errors.md) | Tiempo hasta la respuesta de la DIAN |
| `webhook-failures` | [webhook-failures.md](webhook-failures.md) | ninguno: los webhooks se entregan al menos una vez, sin un objetivo de servicio propio |
| `origin-verifier-errors` | [origin-verifier.md](origin-verifier.md) | Disponibilidad de la API |
| `notifier-failures` | [notifier.md](notifier.md) | ninguno directamente: hace que todas las demás alarmas sigan llegando a los operadores |
| `database-capacity` (producción) | [database-capacity.md](database-capacity.md) | Latencia de la API |
| `database-connections` (producción) | [database-capacity.md](database-capacity.md) | Disponibilidad de la API |
| `service-degraded` (producción) | [service-degraded.md](service-degraded.md) | Disponibilidad de la API, Latencia de la API, Tiempo hasta la respuesta de la DIAN |

Los objetivos de servicio (docs/es/observability.md):

- **Disponibilidad de la API**: el 99,9 % de las peticiones a la API en 30 días no devuelven ningún 5xx (un presupuesto de errores de 43 minutos al mes).
- **Latencia de la API**: el 99 % de las peticiones a la API en 30 días reciben una respuesta en 1 segundo o menos, medido en API Gateway.
- **Tiempo hasta la respuesta de la DIAN**: de los documentos emitidos en 30 días, la DIAN acepta o rechaza el 99 % en los 5 minutos siguientes a su emisión, sin contar los minutos en que la propia DIAN está caída.

## Procedimientos

| Procedimiento | Runbook |
| --- | --- |
| Rotar un secreto fuera de lo programado: el secreto del borde, la dirección del webhook del chat o las credenciales de la base de datos | [rotate-secrets.md](rotate-secrets.md) |
| Atender a un cliente que reporta un error, con el id de correlación de la respuesta que recibió | [customer-report.md](customer-report.md) |
| Devolver los mensajes fallidos a su cola con el redrive nativo de SQS | [dead-letters.md](dead-letters.md), paso 5 |
| Desplegar, revertir, desmontar | [docs/es/deployment.md](../deployment.md) |

## Simulacro

[drill-report.md](drill-report.md) es el informe de una ejecución de `make cloud-drill`, el simulacro en el que se cae la DIAN. Es una revisión de incidente sin culpables: qué pasó, la línea de tiempo, el impacto, qué funcionó y qué mejorar.

## Antes de cualquier runbook

1. **Revisa todas las alarmas a la vez.** Emulador: `make cloud-alarms`. AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]" --output table`, y `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-name-prefix dian-einvoice-prod-` para la alarma compuesta.
2. **Abre el tablero** `dian-einvoice-prod-operations` en CloudWatch. Muestra la API, el borde, la cadena, las colas, la DIAN, el outbox y las funciones frente a sus límites. El emulador guarda el tablero, pero no lo dibuja.
3. **Escribe en el chat** adonde llegó la alarma: quién la atiende y cuándo llega la próxima actualización. Por el canal de estado que mantienen, los operadores les avisan a las empresas que el servicio está degradado; este proyecto no tiene ninguno (docs/es/limitations.md).
