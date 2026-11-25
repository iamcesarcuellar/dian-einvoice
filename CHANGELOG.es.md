# Registro de cambios

[English](CHANGELOG.md) · **Español**

<!-- Traducción de CHANGELOG.md con SHA-256 7c44a48dbfae14ca0f0b0a057d913d592e36a8b85fb44479dc987a5a545d566f. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Aquí se registran todos los cambios notables de dian-einvoice. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/), y las versiones siguen el [Versionado Semántico](https://semver.org/lang/es/spec/v2.0.0.html).

## [1.1.1] - 2026-11-25

Una corrección en el editor de facturas, los dos avisos moderados de la aplicación web y una palabra de la referencia de la API en español. Ninguna funcionalidad nueva: la API y su referencia son las mismas.

### Interfaz

- Una factura guardada o emitida antes de que el editor cargara sus impuestos salía sin impuesto. Cada línea escrita sin un ítem quedaba sin impuesto, aunque un instante después el editor mostraba el IVA. Ahora el editor espera sus rangos de numeración, sus impuestos y sus retenciones. Mientras no llegan, "Guardar borrador" y "Emitir factura" quedan desactivados, y si una de esas listas no carga, el editor muestra el error.
- Una factura emitida mientras esas listas todavía cargaban podía quedarse en "¿Salir sin guardar?" en vez de abrirse. Un documento recién emitido o eliminado, o un borrador nuevo que se guarda y pasa a su propia página, no tiene cambios sin guardar: nada de lo que llegue después impide que la página salga. `make cloud-e2e` falló así una vez, con las funciones en frío.

### Revisiones

- Las pruebas del editor retienen su numeración y sus impuestos, y luego guardan o emiten apenas el editor lo permite. En la 1.1.0 encuentran los dos problemas de arriba.

### Seguridad y dependencias

- `pnpm audit` ya no reporta glob (GHSA-3g3m-2fjr-6pw4) ni minimatch (GHSA-g3hv-75wg-m249) en la aplicación web. Los dos avisos son moderados, y los dos paquetes llegan a través de js-beautify, que declara @vue/test-utils. js-beautify y @vue/test-utils están en sus últimas versiones, y sus rangos no alcanzan las correcciones. Por eso frontend/pnpm-workspace.yaml fija esas dos rutas con un override, en glob 14.0.6 y minimatch 11.0.9. Solo la línea de comandos de js-beautify los carga, y las pruebas nunca la corren.

### Documentación

- La referencia de la API en español llama "Estado" al grupo Health, en vez de "Salud".
- docs/es/limitations.md ya no lista la carrera del editor de facturas, y dice que un override cierra un aviso moderado que ninguna versión de origen corrige.

## [1.1.0] - 2026-11-24

La documentación y la referencia de la API en español, un presupuesto de memoria propio para las pruebas del backend, y el mantenimiento después de la 1.0.1. La API es la misma, y su referencia la sigue llamando 1.0.0.

### Referencia de la API

- La referencia de la API también está en español: la página en /docs/api/es, su documento en /docs/api/es.json, y openapi.es.json en la raíz, que `make openapi` escribe junto a openapi.json. Describe el mismo contrato. Solo se traducen los textos que lee una persona: los resúmenes, las descripciones y los nombres de los grupos, 322 bloques en un solo catálogo cuya clave es el texto en inglés (backend/resources/api-reference/es.json). Las rutas, los nombres, los valores, los ejemplos, los códigos de estado, los nombres de las cabeceras y los tipos de medio siguen en inglés, igual que los rótulos de Stoplight Elements, su visor.
- Cada referencia enlaza a la otra en la primera línea de su descripción, y la página en español declara su idioma. El diálogo Acerca de y el Inicio rápido de Configuración → Desarrolladores abren la referencia en el idioma de la interfaz.

### Observabilidad

- El redactor de los registros conserva enteros todos los UUID. Un UUID cuyo primer o último bloque era solo de dígitos perdía ese bloque por la regla de los números de identificación ("[number]-…"), y un UUID pegado a un prefijo, como `request_<uuid>`, se enmascaraba entero como un token. El primer bloque de un UUID aleatorio es solo de dígitos el 2,3 % de las veces, y el último, el 0,4 %, así que la prueba del redactor, que sacaba uno al azar, fallaba de vez en cuando. Ahora la prueba usa UUID fijos: uno con el primer bloque en dígitos, uno con el último bloque en dígitos, uno todo en dígitos y uno corriente. Las direcciones de correo, los NIT, los números de teléfono y los valores de los campos cuyo nombre indica un secreto se siguen enmascarando.

### Seguridad y dependencias

- docs/es/third-party-licenses.md lista cada dependencia de producción con su licencia, sin su versión: las versiones están en los archivos de bloqueo. Subir una versión ya no hace fallar `make security`, como pasaba en cada pull request de Dependabot. Un paquete que llega o se va, o una licencia que cambia, lo sigue haciendo fallar. Un paquete cuyas versiones instaladas declaran licencias distintas aparece con cada una de ellas. El inventario cuenta 472 paquetes.
- El backend pasa a aws/aws-sdk-php 3.407.1, con guzzlehttp/guzzle 8.6.0, guzzlehttp/promises 3.2.0 y guzzlehttp/psr7 3.6.0.
- La aplicación web pasa a @lucide/vue 1.77.0, @tanstack/vue-query 5.108.2, vue 3.5.46 y vue-i18n 11.4.23, y sus pruebas a @vue/test-utils 2.6.0 y vitest 5.1.1. El simulador pasa a @types/node 26.6.6.

### Documentación

- Cada documento viene también en español. Los documentos de docs/ y de docs/runbooks tienen su traducción en docs/es, con los mismos nombres, y SECURITY, CONTRIBUTING, CHANGELOG y NOTICE tienen la suya en la raíz. NOTICE.es.txt es solo de referencia: el archivo que vale es NOTICE. Una línea bajo cada título lleva al otro idioma, y README.es.md enlaza los documentos en español.
- El glosario de docs/es/README.md da un solo término en español para cada término de la interfaz y de los documentos, con las variantes que los textos en español no usan.
- `make cost` y `make licenses` escriben sus páginas en los dos idiomas a partir de los mismos datos. Las páginas en español escriben sus cifras en notación colombiana (US$ 1.234,56).
- docs/es/limitations.md dice qué es la traducción y qué no es. El inglés es la fuente, y las revisiones mantienen cada traducción al día con su original y revisan lo que no es prosa, no la prosa misma.
- README.md ahora dice lo que solo decía README.es.md: `make seed LOCALE=es` y `make cloud-demo LOCALE=es` cargan los datos de ejemplo en español, una cabecera `Accept-Language` trae los mensajes de la API en español, y la aplicación web guarda sus mensajes en `frontend/src/i18n`.
- docs/es/infrastructure.md nombra actionlint 1.7.12, la versión que usa compose.yaml.
- Los documentos en español dicen "script", la palabra que usan los ingenieros colombianos, en vez de "guion". El glosario lo toma como el término, y agrega "issue" a las palabras que se quedan en inglés.
- docs/es/limitations.md dice qué traduce la referencia en español y qué no, dos límites de Scramble (da processing como el único valor del filtro electronic_status, y un segundo documento que arma en el mismo proceso pierde parámetros) y una carrera del editor de facturas: una factura emitida antes de que carguen los rangos de numeración y los impuestos pregunta antes de que la página salga, como si tuviera cambios sin guardar.
- CONTRIBUTING.es.md dice cómo un cambio mantiene al día la referencia en español.

### Correcciones

- La traducción sacó a la luz errores de los documentos en inglés, ya corregidos en los dos idiomas:
  - docs/es/api-guide.md: el código de medio de pago `42` es una consignación bancaria, no una transferencia.
  - docs/es/architecture.md: cuatro cifras de la tabla de módulos, las tablas que deja por fuera el modelo de datos, lo que Payments importa de Billing, lo que recibe una empresa nueva y los intentos después de un deadlock.
  - docs/es/deployment.md: las funciones de la aplicación tienen como máximo 60 conexiones a la base de datos, no 72; el entorno admite las etiquetas de versión desde las que corre el flujo; los stacks se borran en el orden inverso al del despliegue.
  - docs/es/observability.md y docs/es/infrastructure.md: qué funciones escriben los eventos de la DIAN, la espera más larga entre los reintentos de un webhook (144 s) y el orden en que se despliega la etapa local.
  - docs/es/threat-model.md: las sesiones que se guardan en DynamoDB, cómo llega a la cola de mensajes fallidos un mensaje ilegible, y un riesgo que aparecía como mitigación.
  - Los runbooks: cada cuánto lee su secreto el verificador de origen, el tema de respaldo del notificador, el título completo de un widget, los tipos de evento que hacen fallar un mensaje y los secretos que leen las funciones en PHP.
- La descripción de la alarma `origin-verifier-errors` (infra/cdk/lib/observability-stack.ts) dice lo que pasa: un verificador en caliente sigue usando los valores que guarda, y uno nuevo no tiene ninguno.
- Las pruebas del backend corren en un contenedor propio, api-test, con un presupuesto de memoria de 1,5 GB. Compartían los 512 MiB de la API de desarrollo, y su pico los pasaba, entre 524 y 568 MiB. Con ese límite, el núcleo recuperaba memoria unas 300 veces por ejecución, y en la integración continua mató una vez el proceso de PHPUnit (código de salida 137). El proceso de las pruebas ahora devuelve su memoria libre antes de bifurcarse, y las pruebas que arman la referencia de la API corren en procesos aparte, así que el pico queda entre 368 y 381 MiB. `make test-backend` imprime el pico y el límite del contenedor al final de cada ejecución.

### Revisiones

- `make docs-check` revisa las traducciones (e2e/docs/translations.mjs). Cada documento tiene su par. Cada traducción escrita a mano lleva el SHA-256 de su original y falla en cuanto el original cambia, hasta que `make docs-stamp` la vuelve a sellar. Una traducción conserva los bloques de código, el código en línea, los enlaces, las cifras y la estructura de su original, sección por sección. En español, también fallan un párrafo que se quedó en inglés y un término que el glosario reemplaza. Las diferencias legítimas se declaran en e2e/docs/translations.json, cada una con su razón. `make test-tools` prueba la revisión, incluidos los casos en los que debe fallar.
- `make docs-check` también revisa los objetivos de make y los archivos que nombran las versiones en español del README, del índice, de la arquitectura, de la guía de la API, de la conformidad con la DIAN y de la estimación de costos, como ya hacía con las versiones en inglés, y los enlaces de los archivos que todavía no están en un commit.
- `make docs-examples` también corre los ejemplos de la guía de la API en español, incluidos sus ejemplos de firmas de webhooks en Node.js y PHP.
- Las pruebas de los runbooks también leen los runbooks y la guía de despliegue en español. Sus comandos existen, cada página tiene su traducción, el índice en español lista cada alarma con su runbook, y cada runbook de una alarma tiene sus seis partes.
- `make security` revisa que NOTICE.es.txt nombre cada archivo de terceros que nombra NOTICE.
- `make docs-check` revisa la referencia en español (e2e/docs/reference.mjs). Sin los textos que lee una persona, es el contrato en inglés. Cada bloque de un texto en inglés tiene su traducción, o está declarado igual en los dos idiomas con su razón, y no sobra ninguna entrada del catálogo. Cada traducción conserva el código en línea, los enlaces y las cifras de su original, y pasa el glosario y la revisión del inglés que quedó sin traducir. openapi.es.json tiene la traducción del catálogo de cada texto. `make test-tools` prueba la revisión, incluidos los casos en los que debe fallar.
- `make test-backend` revisa que openapi.json y openapi.es.json sean lo que genera el código, exportándolos en un proceso aparte; antes, nada revisaba openapi.json. Las dos referencias se analizan sin ninguna advertencia, y armar la española no deja nada en español en la inglesa.
- `make cloud-check` y `make cloud-e2e` también abren la referencia en español, a través del borde y bajo su política.
- La revisión de las traducciones toma un enlace a la referencia de la API, servida o exportada, como un enlace a la referencia en el otro idioma.
- `make docs-check` imprime cada problema que encuentra. Antes terminaba antes de que una lista larga llegara a su salida.

## [1.0.1] - 2026-11-24

Correcciones que salieron al usar la interfaz, una revisión que verifica que nada se salga de su caja, y el mantenimiento que siguió a la primera versión. Ninguna funcionalidad nueva: la API y su referencia son las mismas.

### Interfaz

- La tarjeta "Documentos electrónicos" del tablero ya no corta por la derecha su barra de estados ni su lista "Requiere atención" cuando una fila tiene un detalle largo, como un rechazo del simulador de la DIAN. "Principales clientes" y las dos gráficas también se quedan dentro de sus tarjetas, incluso después de que la ventana se achique.
- "Ver XML" y "Ver respuesta" mantienen el XML dentro del diálogo. Ahí se desplaza hacia los lados, y sus líneas quedan enteras.
- Los dos problemas tenían una sola causa. Un elemento de una cuadrícula CSS sin columnas propias toma como ancho mínimo el contenido que no puede partir en varias líneas, y la cuadrícula crece más allá de su caja. Ahora un estilo base deja que un elemento así sea más angosto que su contenido, que entonces se trunca, se parte en varias líneas o se desplaza dentro de él.
- Las líneas de una nota débito caben en la página a 1440×900 en español: una etiqueta de impuesto como "Excluido (sin impuesto)" se parte en varias líneas dentro de su celda.
- "Requiere atención" dice en qué paso se detuvo un documento fallido ("Falló la transmisión."), en los dos idiomas, en vez del nombre interno del paso.
- En inglés, la actividad del simulador escribe como palabras "not found", "invalid request", "error 500" y "no response".
- La tarjeta "Certificado de firma" empieza por el nombre del titular, así que una línea cortada todavía dice algo útil. El sujeto completo sigue en su tooltip.
- La referencia de la API ya no muestra "Scramble: 5 warnings": ahora Scramble lee cada regla de validación.

### Revisiones

- Una revisión de la disposición en `make e2e` y `make cloud-e2e`, medida en el DOM. Cubre cada ruta y los diálogos principales a 1280×720, 1440×900 y 1920×1080, en inglés y en español. Falla cuando la página se desplaza hacia los lados, cuando un elemento se sale de su tarjeta, de su diálogo, del contenedor de su tabla o de su panel, o queda cortado por ellos, y cuando un diálogo no cabe en la ventana. En la 1.0.0 encontró cada uno de los desbordamientos de arriba.

### Seguridad y dependencias

- El simulador pasa a ESLint 10.15.0 y typescript-eslint 8.74.0, y la app de CDK a aws-cdk-lib 2.278.0. npm audit ya no reporta minimatch en ninguno de los dos. Las plantillas sintetizadas son las mismas.
- cfn-lint 1.61.0, sobre Python 3.15.
- Dependabot ya no propone ni MySQL por encima de la 8.4, lo que mantiene la base de datos local en la versión de Aurora, ni la siguiente versión mayor de TypeScript. No propone ninguna de las dos partes de Playwright: su paquete de npm y su imagen se actualizan juntos, a mano (CONTRIBUTING.es.md).

### Integración continua

- El trabajo Emulated cloud puede correr 40 minutos en vez de 30. Con la caché vacía tardó casi 26.

## [1.0.0] - 2026-11-24

La primera versión: facturación electrónica para pequeñas y medianas empresas colombianas, con la API primero. La DIAN siempre es un simulador local, y nunca se desplegó nada en AWS: la nube corre en un emulador local.

### Facturación

- Contactos, ítems, impuestos (IVA e INC), retenciones (ReteFuente, ReteIVA y ReteICA) y rangos de numeración con su resolución DIAN.
- Facturas de venta, notas crédito y notas débito, de borrador a emitidas. Una nota crédito puede anular su factura, y el saldo de una factura refleja sus notas aceptadas.
- La API calcula cada total, impuesto y retención en cadenas decimales, nunca en punto flotante. La aplicación web los muestra mientras escribes.
- Los números se toman sin saltos ni repeticiones, incluso desde veinte procesos a la vez (`make stress`).

### La cadena del documento electrónico

- El XML UBL 2.1 de cada documento, como lo pide el anexo 1.9, válido contra los XSD oficiales de la caja de herramientas de la DIAN.
- El CUFE y el CUDE, revisados contra los ejemplos del anexo (`make dian-check`).
- Una firma XAdES-EPES con el certificado de la empresa, verificada por xmlsec1. Los certificados son certificados de prueba sintéticos que solo acepta el simulador.
- La transmisión por SOAP (SendBillSync y GetStatus), con reintentos después de 2, 5, 15, 45 y 120 segundos, Reintentar ahora después de una falla y Reenviar después de un rechazo.
- Cuando la cadena firma un documento un día posterior, su fecha de emisión pasa a ese día y su CUFE o CUDE se vuelve a calcular.
- Un simulador local de la DIAN en Node.js: una parte de las reglas del anexo 1.9, modos de falla por empresa y un receptor de webhooks.

### Entrega al cliente

- El AttachedDocument firmado, con el documento y el ApplicationResponse del simulador adentro (anexo 1.9, sección 6.4).
- El PDF, con su código QR y su CUFE, en el idioma que la empresa elige para sus documentos.
- El correo con los dos archivos en un zip y el asunto que pide el anexo, que llega a un buzón local. Enviar de nuevo y Regenerar PDF recuperan una entrega fallida.

### Pagos y cartera

- Pagos registrados y aplicados a una o varias facturas de un cliente, anulados con un motivo, y recibos de caja numerados sin saltos.
- Los pagos simultáneos nunca dejan un saldo por debajo de cero: de veinte que pagan cada uno una décima parte, se registran exactamente diez (`make payments-check`).
- La cartera por edades, las facturas por cobrar de cada cliente y un tablero de lo facturado y lo recaudado.

### API pública y webhooks

- Una API REST bajo `/api/v1`, la misma que usa la aplicación web, con su referencia OpenAPI (`openapi.json`, y `/docs/api` en el stack en marcha).
- Llaves de API por empresa, de solo lectura o de lectura y escritura, cada una con su límite de peticiones. Cualquier `POST` acepta una `Idempotency-Key`.
- Errores como `application/problem+json` (RFC 9457), con un id de petición y un id de correlación.
- Webhooks firmados (Standard Webhooks, HMAC-SHA256) para documentos y pagos, con reintentos después de una falla, un evento de prueba y un receptor local.

### Aplicación web

- Una aplicación web en Vue 3 y TypeScript: el tablero, las facturas y las notas con su franja electrónica y su línea de tiempo, los pagos y la cartera, los contactos, los ítems, los impuestos y las retenciones, la numeración, la configuración de la DIAN, el equipo y la configuración de desarrolladores.
- Roles (propietario, administrador, miembro y lector), invitaciones, restablecimiento de contraseñas, una paleta de comandos y los temas claro y oscuro.

### Idiomas

- Inglés y español de Colombia en la aplicación web, los mensajes de la API, el PDF, los correos y los datos de ejemplo (`make demo LOCALE=es`).

### Multiempresa y seguridad

- Cada tabla del negocio lleva su empresa, y cada consulta se limita a ella.
- Cabeceras de seguridad desde una sola fuente de verdad, con una Content-Security-Policy para la aplicación web y para la referencia de la API.
- Las claves privadas de los certificados se guardan cifradas, con la llave de la aplicación o con el cifrado de sobre de KMS.
- Auditorías de cada dependencia, revisiones de las licencias frente a la licencia MIT y una búsqueda de secretos en el historial de git (`make security`).

### Arquitectura de la nube como código

- Una app de AWS CDK en TypeScript con dos etapas: la etapa local, desplegada en el emulador Floci, y la etapa de producción, sintetizada y validada sin conexión, nunca desplegada. cdk-nag y cfn-lint revisan las dos.
- Un servicio serverless (osls) cuyas funciones corren en Lambda detrás de API Gateway y CloudFront: la API, la cadena, los webhooks, el relay, el programador, la consola, el verificador de origen y el notificador.
- SNS y SQS con colas de mensajes fallidos, S3, DynamoDB, KMS, Secrets Manager, SES y EventBridge Scheduler, cada uno detrás de uno de los puertos de la aplicación.
- En la etapa de producción, una VPC en dos zonas, Aurora MySQL Serverless v2 con identidades de la base de datos que se rotan, y AWS WAF.
- Un solo script de despliegue para todas las etapas (`scripts/deploy.sh`), y un flujo de despliegue que nunca ha corrido.

### Operación y observabilidad

- Líneas de registro en JSON con un solo esquema desde cada función, con un id de correlación que sigue a una petición hasta su último webhook.
- Filtros de métricas, alarmas que nombran cada una su runbook, un tablero de operación y consultas guardadas, y un notificador que publica cada alarma en un chat.
- Un runbook para cada alarma, comandos de operación y un simulacro de incidente en el que se cae la DIAN (`make cloud-drill`).

### Primeros pasos y documentación

- Un solo comando arranca todo en Docker Compose (`make demo`), y `make help` lista todos los objetivos.
- Un README en inglés y en español, la arquitectura, una guía de la API cuyos ejemplos corren, la conformidad con la DIAN, una estimación de costos, y capturas y GIF que genera un script.

[1.1.1]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/iamcesarcuellar/dian-einvoice/releases/tag/v1.0.0
