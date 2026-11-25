# Documentación

[English](../README.md) · **Español**

<!-- Traducción de docs/README.md con SHA-256 44d1af38df77e21a326459e68fc00a5e23f3bff6eaa4a63beff66a4d52b7801e. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Todos los documentos del repositorio, uno por línea, y un glosario de los términos que comparten con la aplicación web. Empieza por el [README](../../README.es.md): qué es el proyecto, cómo correrlo con `make demo` y un recorrido de cinco minutos.

Cada documento está en inglés y en español, con el mismo nombre: los de docs/ en docs/es, los runbooks en docs/es/runbooks, y los de la raíz como README.es.md, SECURITY.es.md, CONTRIBUTING.es.md, CHANGELOG.es.md y NOTICE.es.txt. [../README.md](../README.md) es este índice en inglés. El inglés es la fuente: un cambio en un documento lleva su traducción en el mismo commit, y `make docs-check` falla cuando una traducción no está al día con su original ([CONTRIBUTING.es.md](../../CONTRIBUTING.es.md)).

## El proyecto

- [README.es.md](../../README.es.md): qué es dian-einvoice y qué muestra, cómo empezar, el recorrido, la arquitectura de un vistazo, sus límites y su hoja de ruta.
- [README.md](../../README.md): el mismo README en inglés, con las capturas y los GIF en inglés.
- [architecture.md](architecture.md): la arquitectura de la aplicación: su contexto, sus módulos y cómo se hablan, el modelo de datos, el ciclo de vida de un documento, las decisiones de diseño y las garantías que las pruebas sostienen.
- [api-guide.md](api-guide.md): una guía práctica de la API pública: primeros pasos con curl, idempotencia, errores, paginación, límites de peticiones, versiones y webhooks firmados. `make docs-examples` corre cada ejemplo.
- [openapi.es.json](../../openapi.es.json): la referencia de la API pública en OpenAPI 3.1, en español, que `make openapi` exporta desde el código. El stack en marcha la sirve en http://localhost:8600/docs/api/es.
- [openapi.json](../../openapi.json): la misma referencia en inglés, servida en http://localhost:8600/docs/api. Sus rutas, nombres, valores y ejemplos son los de la española.
- [dian-conformance.md](dian-conformance.md): qué partes del anexo técnico 1.9 de la DIAN implementa el proyecto, cómo revisar cada una, qué no está implementado y las fuentes oficiales.
- [cost.md](cost.md): lo que costaría al mes la etapa de producción en us-east-1. `make cost` genera esta estimación a partir de los precios y los supuestos de `scripts/cost`.
- [limitations.md](limitations.md): lo que el proyecto no hace, o hace solo en parte: la DIAN, la firma, el XML, la entrega, la API, la nube emulada, el borde, la observabilidad y las pruebas.
- [threat-model.md](threat-model.md): los activos, los actores, las fronteras de confianza, las amenazas con sus mitigaciones y los riesgos que quedan.
- [third-party-licenses.md](third-party-licenses.md): cada dependencia de producción con su licencia, y los archivos de terceros que guarda el repositorio. `make licenses` lo genera y `make security` lo revisa.
- [NOTICE.es.txt](../../NOTICE.es.txt): los archivos de terceros que guarda el repositorio, como los esquemas oficiales y las fuentes tipográficas, con sus avisos y sus términos. La licencia MIT no los cubre.
- [SECURITY.es.md](../../SECURITY.es.md): cómo reportar una vulnerabilidad en privado, y qué está dentro del alcance.
- [CONTRIBUTING.es.md](../../CONTRIBUTING.es.md): las reglas del repositorio y las revisiones que corre un cambio.
- [LICENSE](../../LICENSE): la licencia MIT del proyecto.
- [CHANGELOG.es.md](../../CHANGELOG.es.md): lo que trae cada versión, desde la 1.0.0.

## La nube, como código y en un emulador

- [infrastructure.md](infrastructure.md): la arquitectura de producción en AWS, la app de CDK y el servicio serverless, la red y la base de datos de la etapa de producción, el borde, y qué se usa para cada pieza en local y en AWS.
- [observability.md](observability.md): la línea de registro y su esquema, el id de correlación, las métricas, las alarmas y sus objetivos de servicio, el notificador, y cómo seguir una factura.
- [deployment.md](deployment.md): un solo script de despliegue para todas las etapas, lo que necesita una cuenta real, el primer despliegue, cómo revertir y cómo desmontar. Nunca se ha corrido contra una cuenta.

## Operación

- [runbooks/README.md](runbooks/README.md): el índice de los runbooks: cada alarma con su runbook y los objetivos de servicio que defiende.
- [runbooks/api-errors.md](runbooks/api-errors.md): la API devuelve errores (`api-server-errors`).
- [runbooks/api-latency.md](runbooks/api-latency.md): la API está lenta (`api-latency`).
- [runbooks/database-capacity.md](runbooks/database-capacity.md): la base de datos está cerca de sus límites (`database-capacity`, `database-connections`, producción).
- [runbooks/dead-letters.md](runbooks/dead-letters.md): mensajes en las colas de mensajes fallidos de la cadena o de los webhooks.
- [runbooks/dian-errors.md](runbooks/dian-errors.md): la DIAN falla (`dian-error-rate`).
- [runbooks/function-errors.md](runbooks/function-errors.md): funciones que fallan (`function-errors`).
- [runbooks/function-throttles.md](runbooks/function-throttles.md): funciones con throttling (`function-throttles`).
- [runbooks/notifier.md](runbooks/notifier.md): el notificador de alarmas falla (`notifier-failures`).
- [runbooks/origin-verifier.md](runbooks/origin-verifier.md): el verificador de origen no puede leer el secreto del borde (`origin-verifier-errors`).
- [runbooks/outbox-relay.md](runbooks/outbox-relay.md): eventos que esperan en el outbox, o el relay se detiene (`relay-not-running`, `outbox-lag`).
- [runbooks/queue-backlog.md](runbooks/queue-backlog.md): una cola que deja de avanzar (`chain-queue-age`, `webhooks-queue-age`).
- [runbooks/service-degraded.md](runbooks/service-degraded.md): el servicio está degradado (`service-degraded`, producción).
- [runbooks/webhook-failures.md](runbooks/webhook-failures.md): webhooks que fallan (`webhook-failures`).
- [runbooks/rotate-secrets.md](runbooks/rotate-secrets.md): rotar un secreto fuera de lo programado.
- [runbooks/customer-report.md](runbooks/customer-report.md): ayudar a un cliente a partir del id de correlación de una respuesta.
- [runbooks/drill-report.md](runbooks/drill-report.md): la revisión de una ejecución de `make cloud-drill`, el simulacro de incidente en el que se cae la DIAN.

## Imágenes

- [assets/](../assets/): las capturas y los GIF del README. `make screenshots` los hace a partir de los datos de ejemplo sintéticos, con el reloj de la demo (jueves 19 de noviembre de 2026, en Medellín).
- [assets/es/](../assets/es/): las mismas capturas y los mismos GIF en español, para README.es.md, con los datos de ejemplo en español y la aplicación web en español.
- El repositorio escribe los archivos PNG y GIF con su propio codificador, [e2e/screenshots/encode.mjs](../../e2e/screenshots/encode.mjs). No necesita más que Node.js, así que no hay binarios nativos de imágenes que instalar ni en los que confiar. `make test` corre sus pruebas.

## Glosario

Los documentos y la aplicación web usan estos términos, siempre en este sentido.

| Término | Significado |
| --- | --- |
| Empresa | Un tenant de la plataforma. Cada tabla del negocio lleva `company_id`, y cada consulta se limita a la empresa actual. |
| Documento | Una factura, una nota crédito o una nota débito. |
| Borrador | Un documento que aún no se emite. Todavía se puede editar, y no tiene número. |
| Emitir | Darle a un borrador su número y su CUFE o CUDE. Un documento emitido ya no se puede editar. |
| Documento electrónico | El XML UBL 2.1 firmado de un documento, con su CUFE (una factura) o su CUDE (una nota). |
| Cadena | Los pasos que llevan un documento emitido a la DIAN y luego al cliente: armar el XML, firmarlo, transmitirlo, leer la respuesta de la DIAN y entregarlo. |
| Transmisión | El envío de un documento firmado a la DIAN (SendBillSync), con sus reintentos. |
| Respuesta de la DIAN | Lo que la DIAN, aquí el simulador, devuelve para una transmisión: aceptada o rechazada, con su ApplicationResponse. |
| Aceptada, rechazada, fallida | Aceptada o rechazada por la DIAN, aquí el simulador. Fallida: la transmisión o la entrega se rindió después de sus intentos. |
| Reintentar ahora | La acción, en la aplicación web y en la API, que vuelve a correr una transmisión o una entrega que espera o que falló. |
| Entrega | El AttachedDocument, el PDF y el correo que recibe el cliente cuando la DIAN acepta el documento. Un webhook también tiene entregas: la entrega de un webhook. |
| Rango de numeración | Un rango de números que autoriza la DIAN, con su resolución, su prefijo y su clave técnica (**Configuración → Numeración**). |
| Simulador de la DIAN | El reemplazo local del servicio web de la DIAN, en `simulator/`. Nunca es la DIAN. |
| Modo de falla | Una opción del simulador que lo hace fallar a propósito: Error 500, sin respuesta, lento, intermitente, o rechazar con una regla. |
| Receptor de webhooks | Una herramienta de desarrollo dentro del simulador que recibe webhooks y revisa sus firmas. |
| Buzón local | Mailpit, adonde llega cada correo en local. No reenvía ninguno. |
| Datos de ejemplo | Las empresas, las personas y los documentos sintéticos que carga `make demo`. |
| Stack local | El entorno de Docker Compose de `make up`: la API, la aplicación web, los workers, el relay, el simulador y el buzón local, todos corriendo todo el tiempo. |
| Nube emulada | La etapa local de la arquitectura de AWS, que `make cloud-up` despliega en el emulador. |
| Emulador | Floci, el emulador local de AWS. |
| Etapa | Un despliegue de la app de CDK y del servicio serverless: la etapa local, desplegada en el emulador, y la etapa de producción, sintetizada y validada sin conexión, pero nunca desplegada. |
| Función | Una función Lambda del servicio serverless: api, chain, webhooks, relay, scheduler, console, el verificador de origen o el notificador. |
| Borde | La distribución de CloudFront delante de la aplicación web y de la API, bajo un solo dominio. |
| Secreto del borde | El secreto que CloudFront envía en `X-Origin-Verify`. El verificador de origen, un autorizador Lambda, solo deja pasar las peticiones que lo traen. |
| Outbox | La tabla donde un cambio registra sus eventos en la misma transacción. El camino rápido los publica justo después del commit, y el relay publica los que quedan rezagados. |
| Recorrido | Todo lo que pone en marcha una petición: funciones, colas, llamadas a la DIAN, correos y webhooks. |
| Id de correlación | El ULID que sigue a un recorrido, en la cabecera `X-Correlation-Id` y en cada línea de registro. |
| Cola de mensajes fallidos | Adonde va un mensaje cuando su trabajo se rinde definitivamente. Sus mensajes son mensajes fallidos, y un redrive los devuelve a su cola. |
| Runbook | Lo que hay que hacer cuando salta una alarma. Cada alarma nombra el runbook que la atiende. |
| Objetivo de servicio | Una meta que defienden las alarmas: la disponibilidad de la API, la latencia de la API y el tiempo hasta la respuesta de la DIAN. |
| Identidad de la base de datos | Uno de los dos usuarios de la base de datos: la identidad de migraciones, solo para la función de consola, y la identidad de la aplicación, para todas las demás funciones. |
| Llave de API | Una llave bearer (`dei_...`) de una empresa, de solo lectura o de lectura y escritura. |
| Aplicación web | La aplicación en Vue de `frontend/`, que usa la misma API pública que las integraciones. |

### La interfaz en español

La aplicación web, los mensajes de la API, el PDF y los correos salen en inglés o en español de Colombia. Usan estos términos en español, siempre los mismos, igual que [README.es.md](../../README.es.md) y la documentación en español. Los términos propios de la DIAN son los oficiales. La tercera columna lista las variantes que los textos en español no usan: `make docs-check` falla cuando una aparece en un documento en español.

| Inglés | Español | En vez de |
| --- | --- | --- |
| Electronic sales invoice | Factura electrónica de venta | factura electrónica de ventas |
| Invoice | Factura | |
| Credit note | Nota crédito | nota de crédito |
| Debit note | Nota débito | nota de débito |
| Document (an invoice or a note) | Documento | |
| Electronic document | Documento electrónico | |
| Graphic representation (the PDF) | Representación gráfica | representación impresa |
| Draft | Borrador | |
| Issue, issued | Emitir, emitida | |
| Number | Número | |
| Prefix | Prefijo | |
| Numbering, numbering range | Numeración, rango de numeración | |
| Numbering resolution, DIAN resolution | Resolución de numeración, resolución DIAN | |
| Technical key | Clave técnica | llave técnica |
| Customer | Cliente | |
| Bill to (the buyer on the PDF) | Adquiriente | adquirente |
| Contact | Contacto | |
| Final consumer | Consumidor final | |
| Item | Ítem | |
| Product, service | Producto, servicio | |
| Unit of measure | Unidad de medida | |
| Quantity | Cantidad | |
| Unit price | Precio unitario | |
| Discount | Descuento | |
| Line | Línea | |
| Notes (on a document or a payment) | Observaciones | |
| Tax | Impuesto | |
| Withholding | Retención | |
| ReteFuente | Retención en la fuente (ReteFuente) | |
| Subtotal, total | Subtotal, total | |
| Net to receive | Neto a recibir | |
| Total in words | Valor en letras | |
| Payment form: cash, credit | Forma de pago: contado, crédito | |
| Payment means | Medio de pago | |
| Issue date | Fecha de emisión | |
| Due date | Fecha de vencimiento | |
| Concept (of a note) | Concepto | |
| Void, voided | Anular, anulada | |
| Payment | Pago | |
| Payment receipt | Recibo de caja | |
| Receivables | Cartera | |
| Receivables by age | Cartera por edades | |
| Open invoices (with a balance to collect) | Facturas por cobrar | |
| Days past due | Días de mora | |
| Balance | Saldo | |
| Customer credit | Saldo a favor | |
| Invoiced, collected | Facturado, recaudado | |
| Overdue | Vencida | |
| Accepted, rejected, failed | Aceptada, rechazada, fallida | |
| Processing | En proceso | |
| Delivery, delivered | Entrega, entregada | |
| Retry now | Reintentar ahora | |
| Resend | Reenviar | |
| Send again | Enviar de nuevo | |
| Timeline | Línea de tiempo | |
| Chain | Cadena | |
| Transmission, attempt | Transmisión, intento | |
| DIAN response | Respuesta de la DIAN | |
| Test environment | Ambiente de pruebas | |
| Habilitación (test) environment | Ambiente de habilitación (pruebas) | |
| Test set | Set de pruebas | conjunto de pruebas |
| Test document | Documento de prueba (DOCUMENTO DE PRUEBA en el PDF) | |
| Local simulator, local DIAN simulator | Simulador local, simulador local de la DIAN | |
| Not affiliated with the DIAN | Sin relación con la DIAN | |
| Failure mode | Modo de falla | |
| Rule, rejection, notification | Regla, rechazo, notificación | |
| Signing certificate | Certificado de firma | |
| Test certificate | Certificado de prueba | |
| Private key | Clave privada | llave privada |
| Software ID, PIN | ID del software, PIN | |
| Local mailbox | Buzón local | |
| Webhook, event | Webhook, evento | |
| Subscription | Suscripción | |
| Webhook receiver | Receptor de webhooks | |
| Signing secret | Secreto de firma | |
| API key | Llave de API | clave de API, API key |
| Read only, read and write | Solo lectura, lectura y escritura | |
| Request, response | Petición, respuesta | solicitud |
| Company | Empresa | |
| Legal name | Razón social | |
| Trade name | Nombre comercial | |
| NIT, check digit | NIT, dígito de verificación | |
| Organization type: legal entity, natural person | Tipo de persona: persona jurídica, persona natural | |
| Identification type | Tipo de identificación | |
| Tax scheme | Tributo | |
| Fiscal responsibilities | Responsabilidades fiscales | |
| Commercial registration | Matrícula mercantil | |
| Department, municipality | Departamento, municipio | |
| Billing email | Correo de facturación | |
| Team, member | Equipo, miembro | |
| Owner, admin, member, viewer | Propietario, administrador, miembro, lector | |
| Invitation | Invitación | |
| Dashboard | Tablero | dashboard |
| Settings | Configuración | |
| Developers | Desarrolladores | |
| Profile | Perfil | |
| Sign in, sign out | Iniciar sesión, cerrar sesión | |
| Password | Contraseña | |
| Command palette | Paleta de comandos | |
| Theme: light, dark, system | Tema: claro, oscuro, sistema | |
| Language | Idioma | |
| Document language | Idioma de los documentos | |
| Demo data, synthetic | Datos de ejemplo, sintético | datos de demostración |
| Emulated cloud, emulator | Nube emulada, emulador | |
| Correlation id | Id de correlación | identificador de correlación |
| Dead-letter queue | Cola de mensajes fallidos (dead-letter queue) | cola de letras muertas, cola de mensajes muertos |

### La documentación en español

Los términos técnicos de los documentos, con el único término en español que lleva cada uno en docs/es. Algunos se quedan en inglés, como los dicen los ingenieros colombianos: webhook, outbox, relay, workers, stack, serverless, runbook, redrive, throttling, failover, payload, endpoint, bucket, commit, push, issue y pull request. Los nombres de campos, eventos, alarmas, funciones, stacks, servicios y cabeceras HTTP quedan como los escribe el código.

| Inglés | Español | En vez de |
| --- | --- | --- |
| Multi-tenant | Multiempresa | multiinquilino, multitenant |
| Tenant | Empresa (un tenant de la plataforma) | inquilino |
| Web app | Aplicación web | web app |
| API first | Con la API primero | |
| Public API, API reference | API pública, referencia de la API | |
| Module | Módulo | |
| Local stack | Stack local | |
| Outbox, outbox event | Outbox, evento del outbox | |
| Fast path | Camino rápido | ruta rápida |
| Queue | Cola | |
| Topic (SNS) | Tema | tópico |
| Schedule | Programación | |
| Scheduler | Programador | planificador |
| Trigger | Disparador | |
| Function, console function | Función, función de consola | |
| Origin verifier | Verificador de origen | |
| Notifier | Notificador | |
| Edge, edge secret | Borde, secreto del borde | |
| Origin | Origen | |
| Distribution, behavior (CloudFront) | Distribución, comportamiento | |
| Cache policy, response headers policy | Política de caché, política de cabeceras de respuesta | |
| Viewer (of CloudFront) | Visitante | |
| Firewall (AWS WAF) | Firewall | cortafuegos |
| Header (HTTP), security headers | Cabecera, cabeceras de seguridad | encabezado HTTP, header |
| Stage: local stage, prod stage | Etapa: etapa local, etapa de producción | stage |
| Real account | Cuenta real | |
| Deployment, deploy | Despliegue, desplegar | |
| Script, deployment script | Script, script de despliegue | |
| Step | Paso | |
| Smoke test | Prueba de humo | |
| Roll back, rollback | Revertir, reversión | rollback |
| Tear down | Desmontar | |
| Template, synthesize | Plantilla, sintetizar | |
| Finding | Hallazgo | |
| Image, container, volume | Imagen, contenedor, volumen | |
| Lock file | Archivo de bloqueo | lockfile |
| Production dependency | Dependencia de producción | |
| License, notice, advisory | Licencia, aviso, aviso | |
| Third-party | De terceros | |
| Fingerprint (the SHA-256 of a file) | Huella | |
| Check (noun) | Revisión | |
| Browser test, end-to-end test | Prueba en el navegador, prueba de punta a punta | |
| Assertion, baseline | Aserción, línea base | |
| Offline | Sin conexión | |
| Layout | Disposición | |
| Screenshot | Captura | pantallazo |
| Story clock (of the demo) | Reloj de la demo | |
| Log, log line, log group | Registro, línea de registro, grupo de registros | |
| Redactor (of the logs), mask | Redactor (la clase que enmascara los registros), enmascarar | |
| Retention (of logs and data) | Conservación | |
| Metric, metric filter, metric math | Métrica, filtro de métricas, métricas calculadas | |
| Alarm, composite alarm | Alarma, alarma compuesta | |
| Saved query | Consulta guardada | |
| Operations dashboard | Tablero de operación | |
| Service objective, error budget | Objetivo de servicio, presupuesto de errores | |
| Journey | Recorrido | viaje |
| Drill | Simulacro | drill |
| Runbook, procedure, operator | Runbook, procedimiento, operador | |
| Dead letters | Mensajes fallidos | letras muertas |
| Purge | Vaciar | |
| Backlog | Mensajes acumulados | |
| Visibility timeout | Tiempo de visibilidad | |
| Batch, partial batch response | Lote, respuesta parcial por lotes | |
| Event source mapping | Mapeo de origen de eventos | |
| Cold start, warm | Arranque en frío, en caliente | inicio en frío |
| Timeout | Tiempo de espera | |
| Reserved concurrency | Concurrencia reservada | |
| Connection budget | Presupuesto de conexiones | |
| Rotation, rotate | Rotación, rotar | |
| Alternating users | Usuarios alternos | |
| Writer, reader (of Aurora) | Instancia de escritura, instancia de lectura | |
| Security group | Grupo de seguridad | |
| Subnet: public, private, isolated | Subred: pública, privada, aislada | |
| NAT gateway, internet gateway | NAT gateway, internet gateway | puerta de enlace NAT |
| Gateway endpoint, interface endpoint | Endpoint de gateway, endpoint de interfaz | |
| Flow logs | Registros de flujo | |
| Zone (availability zone) | Zona (zona de disponibilidad) | |
| Envelope encryption | Cifrado de sobre | |
| KMS key, data key | Llave de KMS, llave de datos | clave de KMS, clave de datos |
| Idempotency key | Llave de idempotencia | clave de idempotencia |
| Rate limit | Límite de peticiones | límite de tasa |
| Pagination, cursor | Paginación, cursor | |
| Signed webhook | Webhook firmado | |
| Replay (of a request) | Repetición | |
| Bill (of AWS) | Factura de AWS | |
| Estimate, scenario, assumption, lever | Estimación, escenario, supuesto, palanca | |
| Free tier, always free | Capa gratuita, siempre gratis | |
| On demand | Bajo demanda | |
| Threat model, asset, trust boundary, mitigation | Modelo de amenazas, activo, frontera de confianza, mitigación | |
| Vulnerability, report, scope | Vulnerabilidad, reportar, alcance | |
| Technical annex, DIAN toolbox | Anexo técnico, caja de herramientas de la DIAN | |
| Code list, validation rule | Lista de códigos, regla de validación | |
| Software security code | Código de seguridad del software | |
| Technology provider, issuer | Proveedor tecnológico, emisor | |
| Contingency | Contingencia | |
| Failure, give up | Falla, rendirse | fallo |
| Self-signed, certificate authority, revocation | Autofirmado, autoridad de certificación, revocación | |
| Changelog, release | Registro de cambios, versión | |
| Branch, tag | Rama, etiqueta | |
| Continuous integration, job | Integración continua, trabajo | |
| Translation, original, pair | Traducción, original, par | |
| Stamp (of a translation) | Huella, sellar | |
| Language line | Línea de cambio de idioma | |
