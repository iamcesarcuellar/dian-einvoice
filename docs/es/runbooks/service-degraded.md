# El servicio está degradado: `service-degraded` (producción)

[English](../../runbooks/service-degraded.md) · **Español**

<!-- Traducción de docs/runbooks/service-degraded.md con SHA-256 637da97495acfe9bfab1bdc9f604a9d9cb986f3f68edd5733d0f828b2e6fa40c. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `service-degraded`, una alarma compuesta: cualquiera de `api-server-errors`, `api-latency`, `chain-dead-letters`, `chain-queue-age`, `dian-error-rate`, `relay-not-running`, `origin-verifier-errors` o `function-errors` en `ALARM` |
| Objetivos de servicio | Disponibilidad de la API, Latencia de la API, Tiempo hasta la respuesta de la DIAN |
| En el emulador | Solo en producción: el emulador no corre las acciones de las alarmas compuestas (docs/es/limitations.md). Las alarmas que agrupa corren allí como dicen sus runbooks. |

## Qué significa y a quién afecta

Es la única alarma por la que hay que llamar a quien esté de turno. Alguna parte del servicio del que dependen las empresas está fallando, sea cual sea. Las alarmas del grupo que están en `ALARM` dicen cuál, y cada una tiene su runbook.

Mientras dura, es posible que las empresas no puedan emitir, ni lograr que la DIAN valide sus facturas, ni entregárselas a sus clientes.

## Confírmalo

1. **Qué alarmas del grupo están en `ALARM`**, y desde cuándo. Esas alarmas dicen a dónde ir.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateUpdatedTimestamp,StateReason]" --output table`
2. **El tablero** `dian-einvoice-prod-operations` muestra la API, el borde, la cadena y la DIAN en una sola página. Revisa también si el problema empezó con un despliegue (`sh scripts/deploy.sh prod versions`).

## Causas probables

Cada alarma del grupo tiene sus causas en su propio runbook: [api-errors.md](api-errors.md), [api-latency.md](api-latency.md), [dead-letters.md](dead-letters.md), [queue-backlog.md](queue-backlog.md), [dian-errors.md](dian-errors.md), [outbox-relay.md](outbox-relay.md), [origin-verifier.md](origin-verifier.md), [function-errors.md](function-errors.md).

Varias alarmas a la vez suelen tener una sola causa: un despliegue, la base de datos, la salida de la red a internet o un evento regional de AWS.

## Responde

3. **Una persona coordina**, lo dice en el chat y da la hora de la próxima actualización.
4. **Un despliegue en la última hora**: primero revierte, después pregunta ([api-errors.md](api-errors.md), paso 4).
5. **Si no**, sigue el runbook de la alarma que se disparó primero.
6. **Avísales a las empresas** cuando no puedan emitir o sus facturas estén esperando, y otra vez cuando termine.

## Verifica la recuperación

7. **Todas las alarmas del grupo vuelven a `OK`**, y luego esta.
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-names dian-einvoice-prod-service-degraded --query "CompositeAlarms[].[StateValue,StateReason]"`

## Después

- Una revisión sin culpables en los días siguientes, escrita como [drill-report.md](drill-report.md): qué pasó, la línea de tiempo, el impacto, qué funcionó y qué mejorar.
- Los minutos que duró, descontados del presupuesto de cada objetivo de servicio.
