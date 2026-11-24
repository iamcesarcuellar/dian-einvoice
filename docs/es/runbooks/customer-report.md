# Un cliente reporta un error

[English](../../runbooks/customer-report.md) · **Español**

<!-- Traducción de docs/runbooks/customer-report.md con SHA-256 f8409e45bccc0d54e5e14a0f18eeca35a3010114038ef0d7c42a5dc58441e19f. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Un procedimiento sin alarma. Una empresa escribe para decir que algo falló: una factura que no salió, o una respuesta de la API que fue un error.

Cada respuesta de la API lleva `X-Correlation-Id`. Cada error `application/problem+json` también lo lleva, como `correlation_id`, junto a `request_id`. Con ese id, una sola consulta muestra cada línea del recorrido, desde la petición hasta sus webhooks, en todas las funciones (mira la sección del id de correlación en docs/es/observability.md).

1. **Pide el id de correlación, y solo eso**: el `X-Correlation-Id` de la respuesta, el `correlation_id` del error, o la hora y la ruta de la petición. Nunca pidas una contraseña, una llave de API, un certificado ni datos de sus clientes. El id basta, y no es un secreto.

   Una integración debería registrar el id con cada respuesta. La aplicación web no se lo muestra a sus usuarios (docs/es/limitations.md). Un usuario de la aplicación web lo encuentra en las herramientas de desarrollo del navegador, en la respuesta a la petición que falló. Si no, puede dar la hora y lo que hizo.
2. **Sigue el recorrido**: cada línea de la petición y de los trabajos que puso en marcha, en orden, con la función, el evento, el resultado y el mensaje de cada línea.
   - Emulador: `make cloud-follow ID=<correlation id> HOURS=24`
   - AWS: la consulta guardada `dian-einvoice/prod/follow-a-journey` en CloudWatch Logs Insights, con el id en lugar de `REPLACE-WITH-THE-CORRELATION-ID`, sobre los días que han pasado desde la petición.
3. **Lee qué pasó.** La primera línea es la petición (`http.request`, su ruta y su estado). Nombra al actor (`user:<id>` o `api-key:<id>`) y a la empresa (`company_id`). Comprueba que sea la empresa que escribió, y no digas nada de ninguna otra empresa. Luego lee las líneas que siguen:

   | Qué dicen las líneas | Qué decirle al cliente |
   | --- | --- |
   | `http.request` con un 4xx | La petición se rechazó, y por qué: los `errors` de la respuesta de error, que el cliente tiene. Un 401 o un 403 apunta a la llave o a la sesión (una línea `auth.refused` no nombra ninguna llave) |
   | `http.request` con un 5xx y un `error` | Una falla nuestra: revisa si hay una alarma activa ([api-errors.md](api-errors.md)). El cliente puede reintentar la petición con la misma Idempotency-Key, y tendrá efecto una sola vez |
   | `document.issued`, luego `dian.call` con `failure`, luego `queue.dead_letter` | La DIAN no respondió, y la transmisión se rindió. Vuelve a empezar cuando los mensajes fallidos se devuelvan a su cola ([dian-errors.md](dian-errors.md)). El cliente puede presionar Reintentar ahora |
   | `dian.response` `rejected` y `dian.rule` | La DIAN rechazó el documento por la regla indicada. Hay que cambiar los datos de la factura; luego, Reenviar |
   | `delivery.email` `failure` | Falló el correo a su cliente: el buzón de su cliente o SES lo rechazó. Usa Enviar de nuevo con otra dirección |
   | `webhook.delivery` `failure` | Su endpoint no aceptó el webhook ([webhook-failures.md](webhook-failures.md)) |
   | Ninguna línea | El id es de otra etapa, o es más antiguo que el periodo de conservación de los registros (365 días en producción), o la petición nunca llegó a la API (el firewall, el borde). Pide la hora y la ruta |

4. **Un documento en detalle**, por el `document_id` de una línea: su cadena, su línea de tiempo y sus llamadas a la DIAN.
   - Emulador: `make cloud-artisan ARGS="chain:status --document=<document id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --document=<document id>"' /dev/stdout`
5. **Respóndele al cliente** qué pasó, qué puede hacer y qué hace la plataforma. Deja por fuera los ids internos de otras empresas, y cualquier dato de sus propios clientes que no haya enviado. Cuando la falla fue nuestra, dilo.

## Después

- Una falla de nuestro lado que ninguna alarma detectó: averigua qué alarma la habría detectado.
- Una pregunta que se repite: agrega una frase a la documentación de la API.
