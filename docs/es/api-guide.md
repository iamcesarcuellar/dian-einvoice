# Guía de la API

[English](../api-guide.md) · **Español**

<!-- Traducción de docs/api-guide.md con SHA-256 f1096faf25476960f40cd7dc2eafc1ec485cf1899e105edc09a059d4acb798a7. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Una guía práctica de la API pública bajo `/api/v1`: los primeros pasos con curl contra la demo y luego la idempotencia, los errores, la paginación, los límites de peticiones, las versiones y los webhooks. La [referencia de la API](http://localhost:8600/docs/api/es) del stack en marcha lista cada operación, cada campo y cada respuesta; Scramble la genera a partir del código, y `make openapi` la exporta a [`openapi.es.json`](../../openapi.es.json). Su [versión en inglés](http://localhost:8600/docs/api), exportada a [`openapi.json`](../../openapi.json), describe el mismo contrato.

Cada bloque de comandos de esta página corre en `make docs-examples` contra la demo, en orden y en una sola shell, e imprime lo que muestra el bloque que le sigue; la revisión falla cuando un ejemplo deja de funcionar. Lo mismo pasa con los dos ejemplos de verificación de firmas de webhooks, que corren contra el ejemplo de referencia de Standard Webhooks y contra un webhook real de la demo. Cada ejecución agrega un contacto, un ítem y una factura sintéticos a Demo Comercial SAS; `make seed` vuelve a cargar la demo desde cero.

## Antes de empezar

Corre `make demo`: la API escucha en `http://localhost:8600/api/v1`. Los ejemplos necesitan una shell, curl, grep, sed y cut, y nada más: ni siquiera jq. Cada id es un ULID de 26 caracteres en minúsculas, y el JSON de la API no tiene espacios, así que `grep` y `cut` bastan para leer un campo.

<!-- example -->
```sh
API=http://localhost:8600/api/v1

# El primer id de una respuesta JSON.
first_id() { grep -o '"id":"[0-9a-z]\{26\}"' | head -n 1 | cut -d '"' -f 4; }

curl -s "$API/health"; echo
```

<!-- output -->
```text
{"status":"ok","checks":{"database":"ok","redis":"ok"}}
```

## Primeros pasos

### 1. Crea una llave de API

El propietario o un administrador de una empresa crea las llaves de API en una sesión de la aplicación web: **Configuración → Desarrolladores → Crear llave**, o la misma petición con curl. Una sesión necesita primero la cookie CSRF, y sus peticiones deben venir del origen de la aplicación web, `http://localhost:8601`. La llave llega una sola vez, en `data.key`; la API solo guarda su hash.

<!-- example -->
```sh
JAR=$(mktemp)   # las cookies de la sesión
xsrf() { grep XSRF-TOKEN "$JAR" | cut -f 7 | sed 's/%3D/=/g; s/%2B/+/g; s/%2F/\//g'; }
session() {
  curl -s -b "$JAR" -c "$JAR" -H 'Origin: http://localhost:8601' -H "X-XSRF-TOKEN: $(xsrf)" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"
}

curl -s -b "$JAR" -c "$JAR" -o /dev/null -H 'Origin: http://localhost:8601' http://localhost:8600/sanctum/csrf-cookie
session "$API/auth/login" -d '{"email":"demo@example.com","password":"password"}' > me.json
COMPANY=$(grep -o '"id":"[0-9a-z]\{26\}","name":"Demo Comercial SAS"' me.json | cut -d '"' -f 4)

session "$API/api-keys" -H "X-Company-Id: $COMPANY" -d '{"name":"API guide","level":"read_write"}' > key.json
KEY=$(grep -o '"key":"dei_[A-Za-z0-9_]*"' key.json | cut -d '"' -f 4)
KEY_ID=$(first_id < key.json)
printf '%.16s…\n' "$KEY"
```

<!-- output -->
```text
<visible-part-of-the-key>…
```

Una llave tiene un nivel: `read_write`, o `read_only`, que solo puede leer. También puede llevar una fecha de vencimiento (`expires_at`) y un límite de peticiones propio (`rate_limit_per_minute`).

### 2. Autentícate y elige la empresa

Envía la llave como un token bearer. Una llave pertenece a una sola empresa, así que no necesita `X-Company-Id`; si se envía, debe ser el de la empresa de la llave. Una sesión funciona al revés: debe nombrar su empresa en `X-Company-Id` en cada petición, y su usuario debe ser miembro de ella.

<!-- example -->
```sh
# La empresa de la llave.
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY"
# Sin llave.
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1"
# La llave con otra empresa del mismo propietario.
OTHER=$(grep -o '"id":"[0-9a-z]\{26\}","name":"Demo Servicios SAS"' me.json | cut -d '"' -f 4)
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H "X-Company-Id: $OTHER"
```

<!-- output -->
```text
200
401
403
```

### 3. Crea un contacto y un ítem

Un contacto necesita su identificación, sus datos tributarios y, en Colombia, el código DANE de su municipio: 05001 es Medellín (`GET /reference/municipalities?department=05` lista los de Antioquia). La API calcula el dígito de verificación de un NIT. Cada empresa arranca con los impuestos IVA 19 %, IVA 5 %, IVA 0 % (exento) e INC 8 %; un ítem nombra uno de ellos por su id.

<!-- example -->
```sh
NIT="99$(date +%s | cut -c 3-10)"   # un NIT sintético, nuevo en cada ejecución
CONTACT=$(curl -s --fail-with-body "$API/contacts" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "organization_type": "1", "identification_type": "31", "identification_number": "'"$NIT"'",
  "legal_name": "Guide Customer SAS (synthetic)", "tax_scheme": "01", "fiscal_responsibilities": ["R-99-PN"],
  "email": "billing@guide-customer.example", "address": "Calle 10 # 43-20", "municipality_code": "05001"
}' | first_id)

TAX=$(curl -s "$API/taxes?per_page=100" -H "Authorization: Bearer $KEY" | grep -o '"id":"[0-9a-z]\{26\}","dian_code":"01","name":"IVA 19 %"' | cut -d '"' -f 4)
ITEM=$(curl -s --fail-with-body "$API/items" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "code": "GUIDE-'"$NIT"'", "name": "Consulting hour (synthetic)", "type": "service", "unit_code": "HUR",
  "unit_price": "150000.00", "tax_id": "'"$TAX"'"
}' | first_id)

echo "contact $CONTACT, item $ITEM"
```

<!-- output -->
```text
contact <id>, item <id>
```

Los valores y las cantidades viajan como cadenas decimales, nunca como números de punto flotante.

### 4. Crea y emite una factura

Un borrador lleva el cliente, la forma de pago (`1` contado, `2` crédito, que necesita una `due_date`), el medio de pago (`10` efectivo, `42` consignación bancaria, entre otros) y sus líneas; una línea con un ítem toma de él su código, su precio y su impuesto. La emisión le da a la factura su número y su CUFE en una sola transacción, y arranca la cadena. Con la `Idempotency-Key`, la emisión se puede enviar de nuevo sin riesgo.

<!-- example -->
```sh
INVOICE=$(curl -s --fail-with-body "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"contact_id":"'"$CONTACT"'","payment_form":"1","payment_means_code":"10","lines":[{"item_id":"'"$ITEM"'","quantity":"3"}]}' | first_id)

curl -s --fail-with-body -X POST "$API/invoices/$INVOICE/issue" -H "Authorization: Bearer $KEY" -H "Idempotency-Key: issue-$INVOICE" > issued.json
grep -o '"status":"issued"' issued.json | head -n 1
grep -o '"full_number":"[A-Z]*[0-9]*"' issued.json | head -n 1
grep -o '"cufe":"[0-9a-f]\{96\}"' issued.json | head -n 1 | cut -c 1-20
```

<!-- output -->
```text
"status":"issued"
"full_number":"<number>"
"cufe":"<first-hex-digits>
```

### 5. Sigue su estado

La cadena corre en segundo plano: el XML, su firma, la transmisión al simulador de la DIAN, su respuesta y luego la entrega al cliente. `electronic.status` pasa por `pending`, `generated`, `signed` y `sending` hasta llegar a `accepted`, `rejected` o `failed`, y `electronic.final` pasa a true. `delivery.status` pasa a `delivered` cuando el AttachedDocument, el PDF y el correo están listos. `data.events` es la línea de tiempo de la factura.

<!-- example -->
```sh
i=0
until curl -s "$API/invoices/$INVOICE" -H "Authorization: Bearer $KEY" > invoice.json && grep -q '"delivery":{"status":"delivered"' invoice.json; do
  i=$((i + 1)); [ "$i" -lt 60 ] || break; sleep 1
done
grep -o '"electronic":{"status":"[a-z]*","final":[a-z]*' invoice.json
grep -o '"delivery":{"status":"[a-z_]*"' invoice.json
grep -o '"type":"[a-z_.]*","payload"' invoice.json | cut -d '"' -f 4 | tr '\n' ' '; echo
```

<!-- output -->
```text
"electronic":{"status":"accepted","final":true
"delivery":{"status":"delivered"
created issued xml_generated xml_signed transmission_attempt accepted attached_document_built pdf_generated email_attempt
```

`GET /invoices?electronic_status=processing` lista las facturas que siguen en la cadena. Una factura que falló después de sus reintentos tiene `electronic.can_retry`; `POST /invoices/{id}/retry` la arranca de nuevo, y una rechazada se puede corregir y volver a enviar con `POST /invoices/{id}/resend`.

### 6. Descarga el XML y el PDF

El XML firmado existe desde que la factura se firma, y el PDF, el AttachedDocument y el zip que recibió el cliente, desde que se entrega; antes, estas operaciones devuelven 409.

<!-- example -->
```sh
curl -s --fail-with-body "$API/invoices/$INVOICE/xml" -H "Authorization: Bearer $KEY" -o invoice.xml -w '%{http_code} %{content_type}\n'
curl -s --fail-with-body "$API/invoices/$INVOICE/pdf?download=1" -H "Authorization: Bearer $KEY" -o invoice.pdf -w '%{http_code} %{content_type}\n'
curl -s --fail-with-body "$API/invoices/$INVOICE/package" -H "Authorization: Bearer $KEY" -o invoice.zip -w '%{http_code} %{content_type}\n'
head -c 5 invoice.pdf; echo
```

<!-- output -->
```text
200 application/xml; charset=UTF-8
200 application/pdf
200 application/zip
%PDF-
```

`GET /invoices/{id}/dian-response` devuelve el ApplicationResponse del simulador, y `/attached-document`, el AttachedDocument. Las notas crédito y débito tienen las mismas operaciones bajo `/credit-notes` y `/debit-notes`, y los pagos se registran con `POST /payments`.

## Idempotencia

Cualquier `POST` puede llevar una cabecera `Idempotency-Key`, de 1 a 255 caracteres ASCII visibles. Su valor se guarda 24 horas, y lo comparten todas las llaves y sesiones de la empresa. La primera petición corre, y su respuesta se guarda con la llave en la misma transacción de base de datos que su efecto. Enviarla de nuevo repite esa respuesta, con `Idempotent-Replayed: true`, y no corre nada.

- **La misma llave con otra petición** (otro método, otra ruta u otro cuerpo) devuelve 422: usa una llave nueva para una petición nueva. Dos cuerpos con el mismo JSON escrito de otra forma son la misma petición.
- **La misma llave mientras la primera petición todavía corre** devuelve 409. Espera y envíala de nuevo.
- **Una respuesta de 500 o más** no se guarda: el efecto se revierte y la llave queda libre otra vez.
- **Cualquier otra respuesta se guarda, incluido un 422.** Después de corregir una petición, envíala con una llave nueva.

Crear llaves de API y suscripciones de webhooks, y rotar el secreto de un webhook, no llevan llave de idempotencia: sus respuestas traen un secreto que no se debe guardar.

<!-- example -->
```sh
DRAFT='{"contact_id":"'"$CONTACT"'","lines":[{"item_id":"'"$ITEM"'","quantity":"1"}]}'
curl -s -D first.txt -o first.json "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d "$DRAFT"
curl -s -D again.txt -o again.json "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d "$DRAFT"
head -n 1 first.txt | tr -d '\r'
head -n 1 again.txt | tr -d '\r'
grep -i '^idempotent-replayed' again.txt | tr -d '\r'
[ "$(first_id < first.json)" = "$(first_id < again.json)" ] && echo "one draft"

# La misma llave con otro cuerpo.
curl -s -o other.json -w '%{http_code}\n' "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d '{"lines":[]}'
grep -o '"detail":"[^"]*"' other.json

# Los borradores se pueden eliminar.
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "$API/invoices/$(first_id < first.json)" -H "Authorization: Bearer $KEY"
```

<!-- output -->
```text
HTTP/1.1 201 Created
HTTP/1.1 201 Created
Idempotent-Replayed: true
one draft
422
"detail":"This Idempotency-Key was already used with another request (method, path or body). Use a new key for a new request."
204
```

## Errores

Todo error es un problema, `application/problem+json` (RFC 9457): `type` (siempre `about:blank`), `title`, `status`, `detail`, `instance` (la ruta), `request_id` y `correlation_id`. Un error de validación agrega `errors`, una lista de mensajes por campo. Con `APP_DEBUG` encendido, como en el stack local, un 500 agrega en `debug` la clase, el archivo y la línea de la excepción; con él apagado, ningún detalle interno llega al cliente.

Los mensajes llegan en inglés, o en español cuando `Accept-Language` lo pide (`es`, `es-CO`, `es-419`): el `title`, el `detail` y los mensajes de validación, con los nombres de sus campos. Con cualquier otro idioma, los mensajes llegan en inglés, y la respuesta dice su idioma en `Content-Language`.

| Estado | Cuándo |
| --- | --- |
| 400 | Una sesión sin un `X-Company-Id` válido, una `Idempotency-Key` mal formada, o un inicio de sesión desde fuera del origen de la aplicación web |
| 401 | Sin credenciales, o una llave desconocida, revocada o vencida |
| 403 | Una llave `read_only` que escribe, la llave de otra empresa, una llave en una ruta reservada para la sesión de la aplicación web, o un rol sin el permiso |
| 404 | Un registro que no existe, o que pertenece a otra empresa |
| 405 | Un método que la ruta no acepta |
| 409 | Un estado que no lo permite: una factura ya emitida, un archivo que aún no está listo, un reintento que no se puede hacer ahora, un registro en uso, una llave de idempotencia cuya petición todavía corre |
| 419 | Una escritura de una sesión sin su token CSRF |
| 422 | La validación, los requisitos de una emisión, una llave de idempotencia usada con otra petición, una URL de webhook rechazada |
| 429 | El límite de peticiones, con `Retry-After` |
| 500, 503 | Una falla del lado del servidor; `/health` devuelve 503 cuando una dependencia está caída |

<!-- example -->
```sh
curl -s -D problem.txt -o problem.json "$API/contacts" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{"legal_name":"Incomplete Contact SAS"}'
tr -d '\r' < problem.txt | grep -i '^http\|^content-type\|^x-correlation-id'
grep -o '"title":"[^"]*","status":[0-9]*,"detail":"[^"]*"' problem.json
grep -o '"errors":{.*}' problem.json | grep -o '"[a-z_]*":\["' | cut -d '"' -f 2 | tr '\n' ' '; echo
```

<!-- output -->
```text
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json
X-Correlation-Id: <id>
"title":"Unprocessable Content","status":422,"detail":"The given data was invalid."
organization_type identification_type identification_number tax_scheme fiscal_responsibilities email municipality_code
```

### El id de correlación

Cada respuesta lleva `X-Correlation-Id`, y el problema lo repite. El mismo id sigue a la petición por la cadena, las llamadas a la DIAN, el correo y los webhooks, y cada línea de registro de ese recorrido lo lleva ([observability.md](observability.md#el-id-de-correlación)); un caso de soporte que lo cite se puede rastrear de punta a punta ([customer-report.md](runbooks/customer-report.md)). Un cliente puede enviar el suyo, un ULID, para vincular el recorrido con sus propios registros; cualquier otro valor se reemplaza por un id nuevo.

<!-- example -->
```sh
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H 'X-Correlation-Id: 01kz9r2v0m4c7d8e5f6g9h3j1k' | tr -d '\r' | grep -i '^x-correlation-id'
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H 'X-Correlation-Id: my-own-id' | tr -d '\r' | grep -i '^x-correlation-id' | grep -vc 'my-own-id'
```

<!-- output -->
```text
X-Correlation-Id: 01kz9r2v0m4c7d8e5f6g9h3j1k
1
```

## Paginación, límites de peticiones y versiones

**Paginación.** Las listas vienen por páginas: `page` desde 1, y `per_page` de 1 a 100, 25 por defecto. Cada página lleva `links` (`first`, `last`, `prev`, `next`) y `meta` (`current_page`, `last_page`, `per_page`, `total` y más). `search` filtra por texto, y cada lista tiene sus propios filtros, como `status`, `electronic_status`, `contact_id`, `from` y `to` para los documentos. El orden de cada lista es fijo: no hay un parámetro de ordenamiento.

<!-- example -->
```sh
curl -s "$API/invoices?status=issued&per_page=2&page=1" -H "Authorization: Bearer $KEY" | grep -o '"current_page":1\|"per_page":2' | sort -u
```

<!-- output -->
```text
"current_page":1
"per_page":2
```

**Límites de peticiones.** Cada llave de API puede enviar 120 peticiones por minuto por defecto, o el límite con el que se creó, contadas en ventanas fijas de 60 segundos. Cada respuesta a una petición con llave informa el límite en `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset` (una marca de tiempo Unix), y en `RateLimit-Policy` y `RateLimit`. Por encima del límite, la API devuelve 429 con `Retry-After` en segundos. Una petición idempotente repetida también cuenta. Las vistas previas, la búsqueda y el inicio de sesión tienen sus propios límites.

<!-- example -->
```sh
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" | tr -d '\r' | grep -i '^x-ratelimit-limit\|^ratelimit-policy'
```

<!-- output -->
```text
Ratelimit-Policy: "api-key";q=120;w=60
X-Ratelimit-Limit: 120
```

**Versiones.** La versión va en la ruta: `/api/v1` es la única, y la referencia la llama 1.0.0. Todavía no hay una cabecera de obsolescencia ni una política escrita para una v2.

## Webhooks

Una suscripción de webhooks envía los eventos de una empresa, firmados, a un endpoint HTTPS. Los propietarios y los administradores manejan las suscripciones con su sesión (**Configuración → Desarrolladores**, o `/webhooks`): una llave de API no puede.

**Eventos.** `invoice.issued`, `invoice.accepted`, `invoice.rejected`, `invoice.failed`, `invoice.delivered`, `invoice.paid` e `invoice.voided`; `credit_note.issued`, `credit_note.accepted`, `credit_note.rejected` y `credit_note.delivered`, y los mismos cuatro para `debit_note`; `payment.recorded` y `payment.voided`. `GET /webhooks/event-types` los lista, y `POST /webhooks/{id}/test` envía un `webhook.test`.

**El cuerpo** es JSON: `id` (`evt_` y un ULID), `type`, `created_at`, `company_id` y `data.object`, el documento o el pago tal como lo devuelve la API. Se arma una sola vez y se envía byte por byte en cada intento.

**Las cabeceras** siguen [Standard Webhooks](https://www.standardwebhooks.com): `webhook-id` (el id del evento, el mismo en cada reintento, para que un receptor pueda descartar los duplicados), `webhook-timestamp` (la hora del intento, en segundos Unix) y `webhook-signature`, más `X-Correlation-Id`.

**La firma** es `v1,` seguido del HMAC-SHA256, codificado en base64, de `{webhook-id}.{webhook-timestamp}.{body}`. Su llave es el secreto de la suscripción, `whsec_` y un valor en base64, una vez decodificado. La cabecera puede traer varias firmas separadas por espacios. Un receptor las revisa en tiempo constante y rechaza una marca de tiempo a más de 5 minutos de su reloj; quien envía no aplica ninguna tolerancia. El secreto se muestra una sola vez, cuando se crea la suscripción o se rota su secreto, y una rotación lo reemplaza de inmediato.

**Reintentos.** Un intento espera 5 segundos al endpoint, no sigue redirecciones y solo tiene éxito con un 2xx. Uno fallido se reintenta a los 2, 5, 15, 45 y 120 segundos, cada espera alargada hasta en un 20 % al azar: seis intentos en total. Luego la entrega falla, y `POST /webhook-deliveries/{id}/redeliver` la arranca de nuevo con el mismo `webhook-id`. Cada evento se entrega al menos una vez, y sin un orden particular.

**Desactivación automática.** Después de 20 entregas fallidas seguidas, la suscripción se desactiva sola. Los eventos de prueba no cuentan, un éxito reinicia la cuenta, y `PATCH /webhooks/{id}` con `{"is_active":true}` la vuelve a activar.

**Producción.** Los endpoints deben ser `https` en el puerto 443, el único puerto que las funciones pueden abrir hacia internet: cualquier otro recibe un 422 al crear o cambiar la suscripción, y se rechaza antes de cada intento ([limitations.md](limitations.md#api-y-webhooks)). El stack local también acepta `http` y direcciones privadas, para que la demo pueda entregar al receptor de webhooks del simulador.

La empresa de la demo está suscrita a ese receptor local, que verifica cada firma con los secretos que el backend le registra. Este ejemplo crea otra suscripción, envía un evento de prueba, lee el veredicto del receptor y elimina la suscripción:

<!-- example -->
```sh
RECEIVER=$(session "$API/webhook-receiver" -H "X-Company-Id: $COMPANY" | grep -o '"url":"[^"]*"' | head -n 1 | cut -d '"' -f 4 | sed 's#\\/#/#g')
session "$API/webhooks" -H "X-Company-Id: $COMPANY" -d '{"url":"'"$RECEIVER"'","events":["invoice.accepted"],"description":"API guide"}' > hook.json
HOOK=$(first_id < hook.json)
grep -o '"secret":"whsec_' hook.json

session -X POST "$API/webhooks/$HOOK/test" -H "X-Company-Id: $COMPANY" -o test.json -w '%{http_code}\n'
EVENT=$(grep -o '"evt_[0-9a-z]\{26\}"' test.json | head -n 1 | tr -d '"')
i=0
until session "$API/webhook-receiver" -H "X-Company-Id: $COMPANY" | grep -o "\"webhook_id\":\"$EVENT\"[^}]*\"verification\":\"[a-z_]*\"" > verdict.txt; do
  i=$((i + 1)); [ "$i" -lt 30 ] || break; sleep 1
done
grep -o '"verification":"[a-z_]*"' verdict.txt

session -X DELETE "$API/webhooks/$HOOK" -H "X-Company-Id: $COMPANY" -o /dev/null -w '%{http_code}\n'
```

<!-- output -->
```text
"secret":"whsec_
201
"verification":"valid"
204
```

### Cómo verificar una firma

Las dos funciones reciben el secreto, las tres cabeceras tal como llegaron (con los nombres en minúsculas) y el cuerpo crudo, antes de interpretar el JSON, y dicen si el webhook es auténtico y reciente. `make docs-examples` corre las dos contra el ejemplo de referencia de Standard Webhooks y contra un webhook real de la demo.

En Node.js, solo con `node:crypto`:

<!-- example: node -->
```js
import { createHmac, timingSafeEqual } from 'node:crypto'

export function verifyWebhook(secret, headers, body, toleranceSeconds = 300) {
  const id = headers['webhook-id']
  const timestamp = headers['webhook-timestamp']
  const signatures = headers['webhook-signature']

  if (!id || !/^\d+$/.test(timestamp ?? '') || !signatures) {
    return false
  }

  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > toleranceSeconds) {
    return false
  }

  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64')
  const expected = createHmac('sha256', key).update(`${id}.${timestamp}.${body}`).digest()

  return signatures.split(' ').some((signature) => {
    const [version, value = ''] = signature.split(',', 2)
    const given = Buffer.from(value, 'base64')

    return version === 'v1' && given.length === expected.length && timingSafeEqual(given, expected)
  })
}
```

En PHP, sin librerías:

<!-- example: php -->
```php
<?php

function verify_webhook(string $secret, array $headers, string $body, int $toleranceSeconds = 300): bool
{
    $id = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';
    $signatures = $headers['webhook-signature'] ?? '';

    if ($id === '' || ! ctype_digit($timestamp) || $signatures === '') {
        return false;
    }

    if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
        return false;
    }

    $key = base64_decode(substr($secret, strlen('whsec_')), true);

    if ($key === false) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    foreach (explode(' ', $signatures) as $signature) {
        [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');

        if ($version === 'v1' && hash_equals($expected, $value)) {
            return true;
        }
    }

    return false;
}
```

## Limpieza

Una llave revocada deja de funcionar de inmediato:

<!-- example -->
```sh
session -X POST "$API/api-keys/$KEY_ID/revoke" -H "X-Company-Id: $COMPANY" -o /dev/null -w '%{http_code}\n'
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY"
```

<!-- output -->
```text
200
401
```
