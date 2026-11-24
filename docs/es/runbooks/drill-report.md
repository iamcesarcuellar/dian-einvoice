# Informe del simulacro: se cae la DIAN

[English](../../runbooks/drill-report.md) · **Español**

<!-- Traducción de docs/runbooks/drill-report.md con SHA-256 b31452611a2145ab05e900baa2c9f0617cdd3b02aa72e1b1a233373720577ebf. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Una revisión sin culpables de una ejecución de `make cloud-drill`, el simulacro de incidente de la nube emulada (scripts/cloud-drill.sh). Corrió en la noche del 20 de noviembre de 2026, de 04:22 a 04:26 UTC del 21 de noviembre de 2026.

Fue un simulacro:

- La DIAN era el simulador local, al que se hizo fallar a propósito con uno de sus modos de falla.
- La empresa, su cliente y sus facturas eran sintéticos, creados para la ejecución y borrados después.
- **Todos los tiempos que siguen son cifras del emulador** (Floci 2.5.0 en esta máquina), no de AWS.

El simulacro ensaya de punta a punta la respuesta de [dead-letters.md](dead-letters.md) y de [dian-errors.md](dian-errors.md), con sus propios comandos. También mide el tiempo de detección, de notificación y de recuperación.

## Qué pasó

Primero, la línea base. Una empresa emitió tres facturas. La DIAN las aceptó, y se le entregaron por correo al cliente de la empresa, con sus webhooks. Las dos colas de mensajes fallidos estaban vacías, y la alarma `chain-dead-letters` no estaba en `ALARM`.

Luego se cayó la DIAN: el simulador respondió con HTTP 500 a cada llamada de cada empresa. La empresa siguió emitiendo: cinco facturas a través del borde con una llave de API, como las emite una integración. La API numeró cada una, le dio su CUFE y respondió de inmediato, con su `X-Correlation-Id`. La cadena generó y firmó su XML e intentó transmitirlas.

Cada transmisión falló, reintentó dos veces y se rindió. La etapa local reintenta a los 2 y a los 5 s, y el emulador estira cada espera a cerca de un minuto. Cada documento quedó fallido en `transmit`, y la empresa lo vio así en la aplicación web y en el webhook `invoice.failed`. Una copia del mensaje de cada transmisión fue a la cola de mensajes fallidos de la cadena, con su motivo y el id de correlación de su factura.

El emulador evaluó la alarma `chain-dead-letters` sobre la cantidad de mensajes de esa cola y la pasó a `ALARM`. El notificador la publicó en el chat, con su runbook.

El operador siguió [dead-letters.md](dead-letters.md):

1. Revisó todas las alarmas (`make cloud-alarms`).
2. Leyó los mensajes fallidos sin consumirlos (`make cloud-dead-letters QUEUE=chain`). Había cinco transmisiones en el intento 3 de 3, con HTTP 500, cada una con su empresa, su documento y su recorrido.
3. Siguió el recorrido de una factura con la consulta guardada (`make cloud-follow`). Mostró la petición, el XML, la firma, tres líneas `dian.call` fallidas, `queue.dead_letter` y el webhook de la falla.
4. Leyó la cadena (`make cloud-artisan ARGS="chain:status"`). Cinco documentos estaban fallidos en `transmit`. De las 18 llamadas a la DIAN de los últimos 15 minutos, 15 no obtuvieron respuesta; las otras tres eran las de la línea base.

La sonda de [dian-errors.md](dian-errors.md) dijo que la DIAN estaba caída (`dian:probe` termina con el código 1). Luego el simulacro restableció la DIAN, como volvería sola la real. La sonda lo confirmó. El operador devolvió los mensajes fallidos a su cola con el redrive nativo de SQS (`make cloud-redrive QUEUE=chain`, una tarea de movimiento de mensajes). Cada transmisión empezó una ejecución nueva y se aceptó, y su factura se entregó.

Al seguirlo otra vez después de la recuperación, con el mismo id de correlación, el recorrido de esa factura fue una sola historia de 50 líneas en las funciones api, chain y webhooks. Tenía la petición, las tres llamadas fallidas y el mensaje fallido. Luego venían el paso que se reanudó a partir de él (`chain.resumed`), la llamada que pasó, la aceptación, el correo y los cuatro webhooks.

## Línea de tiempo

| UTC | Desde el inicio | Qué |
| --- | --- | --- |
| 04:22:13 | +0 s | Se cae la DIAN. Las cinco facturas del incidente se emiten a través del borde, y cada una recibe una respuesta inmediata con su `X-Correlation-Id` |
| 04:22:16 | +3 s | La primera llamada a la DIAN sin respuesta; las transmisiones reintentan |
| 04:24:20 | +127 s | Todas las transmisiones del incidente se rindieron: cinco mensajes en la cola de mensajes fallidos de la cadena |
| 04:25:01 | +168 s | El emulador pasa `chain-dead-letters` a `ALARM` en su siguiente evaluación de la cantidad de mensajes de la cola: **detectado** |
| 04:25:01 | +168 s | Su notificación llega al chat y nombra `docs/runbooks/dead-letters.md`: **notificado** |
| 04:25:07 | +174 s | La DIAN vuelve a responder; `dian:probe` lo confirma |
| 04:25:08 | +175 s | Los mensajes fallidos vuelven a la cola de la cadena |
| 04:25:13 | +180 s | La última factura del incidente se acepta y se entrega: **recuperado** |
| 04:26:01 | +228 s | `chain-dead-letters` vuelve a `OK`, y el chat también se entera |

Desde el inicio del incidente:

- **Tiempo de detección**: 168 s.
- **Tiempo de notificación**: 168 s.
- **Tiempo de recuperación**: 180 s (la alarma volvió a `OK` a los 228 s).

De los 168 s, 124 se fueron en los tres intentos de cada transmisión, que el emulador estira a cerca de un minuto por reintento. Otros 41 se fueron en esperar la siguiente evaluación de la alarma, que en el emulador es una vez por minuto. Con la alarma ya en el chat, la respuesta tomó 12 s. Todo el simulacro tomó 245 s, incluidas la línea base y las revisiones.

## Impacto

- Cinco facturas de una empresa esperaron 180 s su validación y su entrega. Ninguna se perdió, se aceptó dos veces ni se entregó dos veces, y la numeración no tuvo saltos (del 1 al 8).
- Durante unos 48 s, cada una se le mostró a la empresa como fallida en `transmit`. La empresa pudo haber presionado Reintentar ahora: cualquiera de los dos caminos de vuelta recupera una factura una sola vez ([dead-letters.md](dead-letters.md), paso 5). El cliente recibió cada factura una sola vez, después de la recuperación.
- Frente al objetivo de servicio: **Tiempo hasta la respuesta de la DIAN** no cuenta los minutos en que la propia DIAN está caída.
- Nada más en la plataforma se vio afectado: la API respondió a cada petición, y los webhooks se entregaron.

## Qué funcionó

- La detección no necesita más que las colas. Una transmisión que se rinde pone su mensaje en la cola de mensajes fallidos de inmediato, y el emulador sí evalúa la alarma sobre la cantidad de mensajes de esa cola. Así, la caída se detectó y se notificó en el emulador, con el runbook en el mensaje.
- Los mensajes fallidos cuentan la historia sin que nadie abra el cuerpo de un mensaje: tipo, empresa, documento, recorrido, intento y motivo.
- Un solo id de correlación, el de la respuesta de la API, siguió a la factura por todas las funciones, desde su petición hasta su último webhook. El mensaje fallido conservó el id, y el paso que se reanudó a partir de él siguió con ese id.
- El redrive nativo no necesita código propio, y cada paso que se rindió vuelve a empezar exactamente una vez. Un mensaje de una ejecución ya reintentada no hace nada, y un Reintentar ahora después del redrive se rechaza una vez que la factura está aceptada.
- Exactamente una vez: una aceptación, un correo y un intento de entrega por factura, y un webhook por evento, 29 en total, cada uno entregado una sola vez.

## Qué mejorar

- **La alarma propia de la DIAN.** `dian-error-rate` usa métricas calculadas, que el emulador nunca evalúa. Por eso la caída solo se detectó cuando las transmisiones se rindieron, casi tres minutos después. En AWS, `dian-error-rate` salta después de diez minutos de errores, y la alarma de los mensajes fallidos, después de tres. Valdría la pena una alarma solo sobre `DianErrors`, una sola métrica que el emulador sí evalúa, para una caída que apenas empieza.
- **Lo que ve la empresa.** Una vez se rindieron sus transmisiones, las facturas aparecieron como fallidas hasta el redrive, aunque la plataforma las iba a reintentar. En una caída larga, eso son horas. Un estado que diga "esperando a la DIAN" diría la verdad. Ese es un cambio del producto, no de la infraestructura.
- **Los reintentos de la etapa local** (2 y 5 s) mantienen corto el simulacro. En producción son cinco reintentos en unos tres minutos, y luego los mensajes fallidos. Las caídas más largas necesitan a un operador cada vez; un redrive automático en cuanto pase `dian:probe` es una mejora posible.
- **El propio simulacro**, en el que esta ejecución encontró un vacío. Las ejecuciones anteriores borraban la empresa temporal apenas se entregaban las facturas, antes de que salieran los webhooks `invoice.delivered` de las facturas del incidente. Contaban solo los webhooks que existían: 24 de 24, cuando se debían 29. Seguir el recorrido hasta su último webhook mostró el vacío. La corrección: el simulacro espera cada webhook que se le debe a una factura antes de borrar la empresa, y comprueba un webhook de cada evento por factura.
- **El emulador** devuelve un reintento programado después del tiempo de visibilidad de 60 s de su cola, sea cual sea la demora del reintento. Así que los tiempos de los reintentos no son los que mostraría AWS (docs/es/limitations.md).
