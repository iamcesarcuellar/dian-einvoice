# Documentation

Every document in the repository, one line each, and a glossary of the terms they share with the web app. Start with the [README](../README.md): what the project is, how to run it with `make demo`, and a five-minute tour.

## The project

- [README.md](../README.md): what dian-einvoice is and what it shows, the quick start, the tour, the architecture at a glance, its limits and its roadmap.
- [README.es.md](../README.es.md): the same README in Spanish, with the screenshots and GIFs in Spanish. The reference documents below are in English.
- [architecture.md](architecture.md): the application's architecture: its context, its modules and how they talk, the data model, a document's life cycle, the design decisions and the guarantees the tests hold.
- [api-guide.md](api-guide.md): a practical guide to the public API: first steps with curl, idempotency, errors, pagination, rate limits, versions and signed webhooks. `make docs-examples` runs every example.
- [dian-conformance.md](dian-conformance.md): which parts of the DIAN's technical annex 1.9 the project implements, how to check each one, what is not implemented, and the official sources.
- [cost.md](cost.md): what the prod stage would cost a month in us-east-1. `make cost` generates this estimate from the prices and assumptions in `scripts/cost`.
- [limitations.md](limitations.md): what the project does not do, or does only in part: the DIAN, the signature, the XML, the delivery, the API, the emulated cloud, the edge, observability and the tests.
- [threat-model.md](threat-model.md): the assets, the actors, the trust boundaries, the threats with their mitigations, and the risks that remain.
- [third-party-licenses.md](third-party-licenses.md): every production dependency with its license, and the third-party files in the repository. `make licenses` generates it and `make security` checks it.
- [NOTICE](../NOTICE): the third-party files in the repository, such as the official schemas and the fonts, with their notices and terms. The MIT License does not cover them.
- [SECURITY.md](../SECURITY.md): how to report a vulnerability privately, and what is in scope.
- [CONTRIBUTING.md](../CONTRIBUTING.md): the repository's rules and the checks a change runs.
- [LICENSE](../LICENSE): the project's MIT License.
- [CHANGELOG.md](../CHANGELOG.md): what each release brings, from 1.0.0 on.

## The cloud, as code and on an emulator

- [infrastructure.md](infrastructure.md): the production architecture on AWS, the CDK app and the serverless service, the prod stage's network and database, the edge, and what serves each piece locally and in AWS.
- [observability.md](observability.md): the log line and its schema, the correlation id, the metrics, the alarms and their service objectives, the notifier, and how to follow an invoice.
- [deployment.md](deployment.md): the single deployment script for every stage, what a real account needs, the first deployment, rolling back and tearing down. It has never been run against an account.

## Operation

- [runbooks/README.md](runbooks/README.md): the runbook index: each alarm with its runbook and the service objectives it defends.
- [runbooks/api-errors.md](runbooks/api-errors.md): the API returns errors (`api-server-errors`).
- [runbooks/api-latency.md](runbooks/api-latency.md): the API is slow (`api-latency`).
- [runbooks/database-capacity.md](runbooks/database-capacity.md): the database is near its limits (`database-capacity`, `database-connections`, prod).
- [runbooks/dead-letters.md](runbooks/dead-letters.md): messages in the chain's or the webhooks' dead-letter queues.
- [runbooks/dian-errors.md](runbooks/dian-errors.md): the DIAN fails (`dian-error-rate`).
- [runbooks/function-errors.md](runbooks/function-errors.md): functions that fail (`function-errors`).
- [runbooks/function-throttles.md](runbooks/function-throttles.md): throttled functions (`function-throttles`).
- [runbooks/notifier.md](runbooks/notifier.md): the alarm notifier fails (`notifier-failures`).
- [runbooks/origin-verifier.md](runbooks/origin-verifier.md): the origin verifier cannot read the edge secret (`origin-verifier-errors`).
- [runbooks/outbox-relay.md](runbooks/outbox-relay.md): events wait in the outbox, or the relay stops (`relay-not-running`, `outbox-lag`).
- [runbooks/queue-backlog.md](runbooks/queue-backlog.md): a queue that stops moving (`chain-queue-age`, `webhooks-queue-age`).
- [runbooks/service-degraded.md](runbooks/service-degraded.md): the service is degraded (`service-degraded`, prod).
- [runbooks/webhook-failures.md](runbooks/webhook-failures.md): webhooks that fail (`webhook-failures`).
- [runbooks/rotate-secrets.md](runbooks/rotate-secrets.md): rotating a secret off schedule.
- [runbooks/customer-report.md](runbooks/customer-report.md): helping a customer from a response's correlation id.
- [runbooks/drill-report.md](runbooks/drill-report.md): the review of one run of `make cloud-drill`, the incident drill in which the DIAN goes down.

## Images

- [assets/](assets/): the README's screenshots and GIFs. `make screenshots` makes them from the synthetic demo data, under the demo's story clock (Thursday, November 19, 2026, in Medellín).
- [assets/es/](assets/es/): the same screenshots and GIFs in Spanish, for README.es.md, from the demo data in Spanish and the web app in Spanish.
- The repository writes the PNG and GIF files with its own encoder, [e2e/screenshots/encode.mjs](../e2e/screenshots/encode.mjs). It needs nothing but Node.js, so there are no native image binaries to install or to trust. `make test` runs its tests.

## Glossary

The documents and the web app use these terms, always in this sense.

| Term | Meaning |
| --- | --- |
| Company | A tenant of the platform. Every business table carries `company_id`, and every query is scoped to the current company. |
| Document | An invoice, a credit note or a debit note. |
| Draft | A document that is not issued yet. It can still be edited, and it has no number. |
| Issue | Give a draft its number and its CUFE or CUDE. An issued document can no longer be edited. |
| Electronic document | A document's signed UBL 2.1 XML, with its CUFE (an invoice) or CUDE (a note). |
| Chain | The steps that take an issued document to the DIAN and on to the customer: build the XML, sign it, transmit it, read the DIAN response and deliver it. |
| Transmission | Sending a signed document to the DIAN (SendBillSync), with its retries. |
| DIAN response | What the DIAN, here the simulator, returns for a transmission: accepted or rejected, with its ApplicationResponse. |
| Accepted, rejected, failed | Accepted or rejected by the DIAN, here the simulator. Failed: the transmission or the delivery gave up after its attempts. |
| Retry now | The action, in the web app and the API, that runs a waiting or failed transmission or delivery again. |
| Delivery | The AttachedDocument, the PDF and the email the customer gets once the DIAN accepts the document. A webhook also has deliveries: a webhook delivery. |
| Numbering range | A range of numbers the DIAN authorizes, with its resolution, prefix and technical key (**Settings → Numbering**). |
| DIAN simulator | The local stand-in for the DIAN web service, in `simulator/`. It is never the DIAN. |
| Failure mode | A simulator setting that makes it fail on purpose: Error 500, no response, slow, intermittent, or reject with a rule. |
| Webhook receiver | A development tool inside the simulator that receives webhooks and checks their signatures. |
| Local mailbox | Mailpit, where every email lands locally. It relays none. |
| Demo data | The synthetic companies, people and documents that `make demo` loads. |
| Local stack | The Docker Compose environment of `make up`: the API, the web app, the workers, the relay, the simulator and the local mailbox, all running all the time. |
| Emulated cloud | The AWS architecture's local stage, deployed to the emulator by `make cloud-up`. |
| Emulator | Floci, the local AWS emulator. |
| Stage | One deployment of the CDK app and the serverless service: the local stage, deployed to the emulator, and the prod stage, synthesized and validated offline but never deployed. |
| Function | A Lambda function of the serverless service: api, chain, webhooks, relay, scheduler, console, the origin verifier or the notifier. |
| Edge | The CloudFront distribution in front of the web app and the API, under one domain. |
| Edge secret | The secret CloudFront sends in `X-Origin-Verify`. The origin verifier, a Lambda authorizer, lets in only the requests that carry it. |
| Outbox | The table where a change records its events in the same transaction. The fast path publishes them right after the commit, and the relay publishes those left behind. |
| Journey | Everything one request sets in motion: functions, queues, DIAN calls, emails and webhooks. |
| Correlation id | The ULID that follows a journey, in the `X-Correlation-Id` header and in every log line. |
| Dead-letter queue | Where a message goes when its job gives up for good. Its messages are dead letters, and a redrive moves them back to their queue. |
| Runbook | What to do when an alarm goes off. Every alarm names the runbook that handles it. |
| Service objective | A target the alarms defend: API availability, API latency and the time to the DIAN response. |
| Database identity | One of the two database users: the migrations identity, for the console function only, and the application identity, for every other function. |
| API key | A bearer key (`dei_...`) of one company, read-only or read-write. |
| Web app | The Vue application in `frontend/`, which uses the same public API as integrations do. |

### The interface in Spanish

The web app, the API's messages, the PDF and the emails come in English or in Colombian Spanish. They use these Spanish terms, always the same ones, and so does [README.es.md](../README.es.md). The DIAN's own terms are its official ones.

| English | Spanish |
| --- | --- |
| Electronic sales invoice | Factura electrónica de venta |
| Invoice | Factura |
| Credit note | Nota crédito |
| Debit note | Nota débito |
| Document (an invoice or a note) | Documento |
| Electronic document | Documento electrónico |
| Graphic representation (the PDF) | Representación gráfica |
| Draft | Borrador |
| Issue, issued | Emitir, emitida |
| Number | Número |
| Prefix | Prefijo |
| Numbering, numbering range | Numeración, rango de numeración |
| Numbering resolution, DIAN resolution | Resolución de numeración, resolución DIAN |
| Technical key | Clave técnica |
| Customer | Cliente |
| Bill to (the buyer on the PDF) | Adquiriente |
| Contact | Contacto |
| Final consumer | Consumidor final |
| Item | Ítem |
| Product, service | Producto, servicio |
| Unit of measure | Unidad de medida |
| Quantity | Cantidad |
| Unit price | Precio unitario |
| Discount | Descuento |
| Line | Línea |
| Notes (on a document or a payment) | Observaciones |
| Tax | Impuesto |
| Withholding | Retención |
| ReteFuente | Retención en la fuente (ReteFuente) |
| Subtotal, total | Subtotal, total |
| Net to receive | Neto a recibir |
| Total in words | Valor en letras |
| Payment form: cash, credit | Forma de pago: contado, crédito |
| Payment means | Medio de pago |
| Issue date | Fecha de emisión |
| Due date | Fecha de vencimiento |
| Concept (of a note) | Concepto |
| Void, voided | Anular, anulada |
| Payment | Pago |
| Payment receipt | Recibo de caja |
| Receivables | Cartera |
| Receivables by age | Cartera por edades |
| Open invoices (with a balance to collect) | Facturas por cobrar |
| Days past due | Días de mora |
| Balance | Saldo |
| Customer credit | Saldo a favor |
| Invoiced, collected | Facturado, recaudado |
| Overdue | Vencida |
| Accepted, rejected, failed | Aceptada, rechazada, fallida |
| Processing | En proceso |
| Delivery, delivered | Entrega, entregada |
| Retry now | Reintentar ahora |
| Resend | Reenviar |
| Send again | Enviar de nuevo |
| Timeline | Línea de tiempo |
| Chain | Cadena |
| Transmission, attempt | Transmisión, intento |
| DIAN response | Respuesta de la DIAN |
| Test environment | Ambiente de pruebas |
| Habilitación (test) environment | Ambiente de habilitación (pruebas) |
| Test set | Set de pruebas |
| Test document | Documento de prueba (DOCUMENTO DE PRUEBA on the PDF) |
| Local simulator, local DIAN simulator | Simulador local, simulador local de la DIAN |
| Not affiliated with the DIAN | Sin relación con la DIAN |
| Failure mode | Modo de falla |
| Rule, rejection, notification | Regla, rechazo, notificación |
| Signing certificate | Certificado de firma |
| Test certificate | Certificado de prueba |
| Private key | Clave privada |
| Software ID, PIN | ID del software, PIN |
| Local mailbox | Buzón local |
| Webhook, event | Webhook, evento |
| Subscription | Suscripción |
| Webhook receiver | Receptor de webhooks |
| Signing secret | Secreto de firma |
| API key | Llave de API |
| Read only, read and write | Solo lectura, lectura y escritura |
| Request, response | Petición, respuesta |
| Company | Empresa |
| Legal name | Razón social |
| Trade name | Nombre comercial |
| NIT, check digit | NIT, dígito de verificación |
| Organization type: legal entity, natural person | Tipo de persona: persona jurídica, persona natural |
| Identification type | Tipo de identificación |
| Tax scheme | Tributo |
| Fiscal responsibilities | Responsabilidades fiscales |
| Commercial registration | Matrícula mercantil |
| Department, municipality | Departamento, municipio |
| Billing email | Correo de facturación |
| Team, member | Equipo, miembro |
| Owner, admin, member, viewer | Propietario, administrador, miembro, lector |
| Invitation | Invitación |
| Dashboard | Tablero |
| Settings | Configuración |
| Developers | Desarrolladores |
| Profile | Perfil |
| Sign in, sign out | Iniciar sesión, cerrar sesión |
| Password | Contraseña |
| Command palette | Paleta de comandos |
| Theme: light, dark, system | Tema: claro, oscuro, sistema |
| Language | Idioma |
| Document language | Idioma de los documentos |
| Demo data, synthetic | Datos de ejemplo, sintético |
| Emulated cloud, emulator | Nube emulada, emulador |
| Correlation id | Id de correlación |
| Dead-letter queue | Cola de mensajes fallidos (dead-letter queue) |
