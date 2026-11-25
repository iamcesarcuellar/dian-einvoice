# Limitaciones conocidas

[English](../limitations.md) · **Español**

<!-- Traducción de docs/limitations.md con SHA-256 04074541b2c204c161a7d7c459687f3ac81db75b4ec9937cebc1467445dc068a. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Esta página lista lo que el proyecto no hace, o solo hace en parte, en su estado actual. El proyecto es una implementación de referencia que corre contra un simulador local de la DIAN, con datos sintéticos. No se usa en producción.

## Relación con la DIAN

- **Sin certificación.** La DIAN nunca ha aceptado un CUFE, un CUDE ni un código de seguridad del software producidos aquí. Cada "aceptada" viene del simulador local, en `simulator/`.
- **Lo que valida los documentos:**
  - el simulador local, con la parte de las reglas del anexo 1.9 que está en `simulator/src/rules.ts`, donde ZD05 es una notificación
  - los XSD oficiales de la caja de herramientas de la DIAN
  - xmlsec1, para las firmas
  - el schematron de 2019 de la caja de herramientas, corrido una vez (`make dian-schematron`). Reporta 24 hallazgos de reglas anteriores al anexo 1.9, y el Makefile lista cada uno con su razón. La ejecución depende de los documentos de la demo.
- **Sin WS-Security.** El servicio web real exige una firma en la cabecera SOAP (sección 7.5), y el proyecto no la construye. El simulador no la revisa, y su ApplicationResponse no está firmado.
- **Solo certificados de prueba.** Los documentos se firman con certificados RSA sintéticos y autofirmados, a los que se les cambian las fechas reescribiendo su DER. Solo el simulador los acepta; la DIAN no los aceptaría.
- **Lo que no se pudo revisar sin el servicio real:**
  - el nombre y el NIT que figuran en el RUT (FAJ43b, FAJ44b, RUT01)
  - los correos de recepción registrados (FAJ71, FAK55)
  - que el emisor sea el titular del certificado (ZE03)
  - la cadena de confianza de la ONAC (ZD05, DC38 a DC46)
  - las respuestas, las demoras y las fallas reales del servicio
  - el set de pruebas de habilitación
- **Llamadas que pudieron haber llegado.** Cuando una llamada no recibe respuesta, el backend juzga por su código de error de cURL si llegó al servicio. El siguiente intento consulta primero GetStatus, y un documento enviado dos veces recibe una respuesta con la regla 90.
- **Solo tres documentos:** la factura electrónica de venta y sus notas crédito y débito, que siempre hacen referencia a una factura. No hay eventos de RADIAN, ni documento equivalente POS, ni documento soporte, ni nómina electrónica, ni facturas de exportación o de contingencia ([dian-conformance.md](dian-conformance.md#lo-que-no-está-implementado)).

## Firma y certificados

- SigningCertificate lista la cadena solo cuando el archivo subido la incluye, mientras que DC25 pide tres entradas Cert.
- X509Data lleva solo el certificado de firma, como el ejemplo Generica.xml de la caja de herramientas, mientras que la sección 10.5.2 pide la cadena completa.
- Las firmas solo usan RSA con SHA-256.
- La hora de la firma sale del reloj del servidor, que no está sincronizado con la hora legal del INM (10.11). La sincronización de la hora queda en manos de la infraestructura.
- No se revisa si el certificado de firma está revocado (CRL u OCSP).
- Los archivos PKCS#12 exportados con cifrado antiguo (RC2, 3DES) no se abren con OpenSSL 3 sin su proveedor legacy. Al subir uno, la respuesta dice que el archivo no se pudo abrir.
- Un certificado debe estar vigente en el momento de la firma. Si vence entre la emisión y la firma, la cadena se detiene en el paso de la firma, antes de enviar nada. El documento se reintenta cuando se sube un certificado vigente.

## Cálculo y XML

- Las retenciones que practica el cliente van en WithholdingTaxTotal como valores informativos. El anexo describe ese grupo para las autorretenciones, y la sección 11.9.1 las deja por fuera de los totales. Las notas no llevan ninguna, porque su XSD no tiene ese grupo.
- Solo software propio (ppp 000, sin proveedor tecnológico). La demo solo tiene ítems con código 999.
- Los teléfonos de más de diez dígitos se dejan por fuera. Las descripciones se parten a los 300 caracteres, y las observaciones a los 500.
- Las tarifas que no están en la tabla 13.3.11 generan una notificación.
- Se conservan los nombres oficiales de los conceptos con menos de los 20 caracteres que piden CBF04 y DBF04, como en los ejemplos de 2023.
- El ejemplo de nota débito de la sección 11.4.5 no cuadra con sus propios datos.
- No se aplica el redondeo opcional del IVA a 10 pesos (5.2.1.2).
- Las tarifas de ReteICA se ingresan como porcentajes (9,66 por mil es 0,966 %).
- El CUFE o el CUDE, la fecha y la hora de emisión y la fecha de vencimiento solo son definitivos cuando el simulador acepta el documento. Si el XML se genera o se firma en un día posterior, cambian (FAD09e). El webhook invoice.issued conserva las primeras fechas.

## Entrega y PDF

- El AttachedDocument se arma a partir de la tabla del anexo, porque la caja de herramientas no trae ningún ejemplo. Su ID se repite cada año. En las notas, sus literales siguen siendo los de una factura. ValidatorID nombra al simulador. El PDF lleva el nombre del XML.
- dompdf dibuja el PDF. Sus advertencias se silencian mientras dibuja, y se revisa que el resultado sea un PDF de al menos una página. El PDF se dibuja dentro del bloqueo de su documento. Los límites de dompdf:
  - sin disposición tipográfica OpenType
  - los números de página y la marca de agua, dibujados por un script de página
  - SVG simplificado
  - márgenes y saltos de página fijos
  - sin PDF/UA ni PDF/A
- El PDF y el correo salen en el idioma de los documentos de la empresa, inglés o español, y en ningún otro. Se revisa el límite de 2 MB de la sección 9.1, pero la demo nunca lo alcanza.
- El correo se entrega al menos una vez. Si un worker o una función muere después de que el servidor de correo lo recibió, el correo se envía de nuevo.
- SPF, DKIM y DMARC son registros DNS de la identidad de dominio de la etapa de producción, que publica el dueño del dominio. Aquí no se administra ninguna zona alojada (mira [Borde](#borde), más abajo).

## API y webhooks

- Las llaves de API tienen dos niveles (read_only, read_write), sin alcances por recurso y sin lista de direcciones IP permitidas. Se guardan como hashes SHA-256, lo que basta para sus 238 bits de aleatoriedad. No se usa pepper.
- Los límites de peticiones cuentan en ventanas fijas de 60 segundos, así que una ráfaga del doble del límite cabe alrededor del cambio de una ventana a la siguiente.
- En la sesión web hay límite de peticiones para el inicio de sesión, el registro, la recuperación de la contraseña, los enlaces de invitación, la creación de empresas, la vista previa de la factura y la búsqueda. Las demás rutas de la sesión no tienen un límite propio.
- En producción, los endpoints de los webhooks deben ser https en el puerto 443, porque las funciones no abren ningún otro puerto hacia internet.
  - Un endpoint en otro puerto no se puede suscribir: crear o cambiar la suscripción devuelve 422.
  - Una suscripción que ya estaba guardada se rechaza antes de cada intento.
- Rotar el secreto de un webhook lo reemplaza de inmediato. No hay un periodo en el que firmen ambos secretos.
- Los webhooks se entregan al menos una vez, sin un orden particular. Su cuerpo se arma cuando se publica el evento. La respuesta de un endpoint se lee hasta 64 KB, y el resto se descarta.
- Una Idempotency-Key se guarda durante 24 horas. Una petición con llave de idempotencia corre en una sola transacción de base de datos junto con su llave, así que un deadlock dentro de ella no se reintenta ahí mismo. La petición devuelve 5xx, y la misma llave se puede volver a usar.
- La referencia de la API (Scramble), en inglés y en español, es pública, y carga Stoplight Elements 8.4.2 (dos archivos) desde unpkg.com.
  - Detrás del borde, comparte el origen de la sesión de la aplicación web. El navegador solo corre esos dos archivos con los bytes que fija su Subresource Integrity.
  - Cada página tiene su propia Content-Security-Policy, que permite sus scripts en línea por su hash (docs/es/infrastructure.md). Sus estilos pueden ir en línea.
  - Un middleware agrega la integridad a la página de Scramble buscando sus dos etiquetas. Una versión de Scramble que cambie esas etiquetas hace fallar la referencia (500), y también su prueba, hasta que se actualice security-headers.json.
- Las acciones hechas con una llave de API no tienen usuario en created_by. Sus líneas de tiempo nombran la llave.

## Pagos y reglas de negocio

- No hay pasarela de pagos: los pagos se registran a mano o por la API. Los pagos de una empresa se registran uno a la vez, bajo un bloqueo sobre la empresa.
- Por diseño:
  - las notas congelan las tarifas de su factura
  - lo que trae prellenado una nota de anulación coincide con la factura
  - los montos acreditados y debitados solo cuentan las notas aceptadas
  - el estado de pago, las facturas vencidas y la cartera por edades siguen las reglas de la cartera
  - los saldos negativos no se pueden usar
  - una anulación libera una aplicación completa
  - los recibos de caja no son documentos electrónicos
  - los borradores se eliminan para siempre
- Payments comparte la base de datos y la transacción con Billing, a través de sus puertos. Workspace lee tablas de otros módulos. Separarlos en servicios cambia las dos cosas.

## Seguridad

- No hay segundo factor (TOTP o WebAuthn).
- El registro no verifica el correo, por decisión de diseño. La cuenta se crea y la sesión se inicia de una vez, así que el registro todavía deja distinguir un rechazo de un éxito. Un rechazo significa que el correo o el NIT ya está registrado. La mitigación: un solo mensaje para un correo ya usado y para un NIT ya usado, sin nombrar el campo, y diez intentos de registro por hora por dirección.
- Las contraseñas usan bcrypt y admiten de 12 a 128 caracteres (bcrypt lee los primeros 72 bytes). No se revisan contra listas de contraseñas filtradas, porque eso necesitaría un servicio externo.
- La llave de cifrado no se rota.
  - APP_KEY cifra los PIN del software, las claves técnicas y los secretos de los webhooks. En el stack local, también cifra el almacén de llaves (los certificados y sus contraseñas).
  - Cambiarla obliga a volver a cifrar todo eso, y la llave vive en la memoria de PHP.
  - En la nube emulada, el almacén de llaves usa cifrado de sobre con una llave de KMS, y APP_KEY viene de Secrets Manager. Ese secreto no tiene función de rotación: rotar APP_KEY necesitaría una que volviera a cifrar cada valor.
  - El secreto de APP_KEY no guarda nada más. Las credenciales de la base de datos están en los secretos de sus dos identidades, que las funciones de rotación de AWS rotan en producción.
- Aceptar una invitación para una dirección nueva con la sesión iniciada como otra persona le cierra la sesión a esa persona. La página lo advierte antes.
- Algunas herramientas de desarrollo corren sin autenticación, solo en 127.0.0.1:
  - el buzón local, que guarda cada enlace para restablecer la contraseña y cada enlace de invitación
  - el receptor de webhooks
  - en la nube emulada, el emulador de AWS, que acepta cualquier credencial y guarda cada correo que recibió SES
- El simulador guarda en texto plano, en su archivo de estado, los PIN y las claves técnicas que se registran en él. La DIAN también los conoce: ella emite las claves técnicas, y el PIN del software se registra ante ella.
- El desarrollo local usa credenciales fijas para la base de datos (dian/dian y root/root, en compose.yaml y backend/.env.example), y el usuario de la aplicación es dueño del esquema que migra. La contraseña de Redis y el token del simulador son aleatorios en cada clon.
- La nube emulada tiene sus propias dos identidades de base de datos, con contraseñas generadas (docs/es/infrastructure.md). Su identidad de migraciones tiene:
  - CREATE USER, para crear la identidad de la aplicación. MySQL lo otorga para el servidor completo, así que en el servidor MySQL compartido de Compose también podría modificar los usuarios del stack local y de las pruebas. No puede modificar a root, que tiene SYSTEM_USER.
  - PROCESS, como el usuario maestro de Aurora, para que php artisan db:connections vea todas las conexiones. En el servidor MySQL compartido de Compose, eso incluye las conexiones del stack local y las de las pruebas.
- La identidad de migraciones de las pruebas (tests/database.sql) tiene los mismos privilegios, por la misma razón.
- Las cabeceras de seguridad de la aplicación web vienen del borde (docs/es/infrastructure.md). El servidor de desarrollo del stack local no manda ninguna, así que make e2e corre sin política y make cloud-e2e corre bajo ella.
- La política de la aplicación web permite, por sus hashes, unos pocos elementos de estilo fijos de librerías (Reka UI, VueUse) y el script en línea de index.html. Una actualización que cambie uno de ellos aparece como una violación en make cloud-e2e, y su hash va a security-headers.json.
- La cabecera Server de FrankenPHP queda a cargo del proxy que va delante.
- Bajo /api/v1, CORS acepta cualquier origen sin credenciales, para las llaves de API. No se ofrece para la cookie de CSRF. Una sesión solo funciona desde los orígenes con estado que están configurados: el origen del borde.
- Las auditorías de dependencias fallan con los avisos de severidad alta y crítica. Los de severidad moderada en herramientas de desarrollo esperan las correcciones de sus proyectos de origen, que Dependabot propone. Al momento de escribir esto, eran glob y minimatch, que llegan a las herramientas de prueba de la aplicación web a través de @vue/test-utils (js-beautify).

## Operación y escala

- El simulador guarda el estado de cada stack en un solo archivo JSON propio, y lo escribe después de cada cambio.
- Las búsquedas usan LIKE, sin índices de texto completo. Las consultas de la actividad reciente y de lo que requiere atención en el tablero no tienen índices propios. La cartera por edades se calcula en PHP en cada petición. El chunk del tablero en la aplicación web es grande.
- make stress usa un solo rango de numeración y una sola empresa. Los reintentos por deadlock y las esperas por bloqueos no se midieron a escala.
- Ninguna prueba de carga ha medido la capacidad que necesita la base de datos. [cost.md](cost.md#supuestos) la supone para cada volumen.
- Cuando se mata un worker en medio de un trabajo, el trabajo espera REDIS_QUEUE_RETRY_AFTER (45 segundos) antes de que otro worker lo tome.
- Los registros operativos viejos se depuran una vez por hora (config/retention.php). En el stack local los depura el relay; en las funciones, el programador de Laravel de la función scheduler.
- Cada servicio tiene un healthcheck. El stack local no tiene métricas ni alertas, y conserva el formato de líneas de Laravel. Los registros estructurados, las métricas, las alarmas y las notificaciones son de las funciones (docs/es/observability.md).
- El target production de la imagen FrankenPHP del backend no copia código ni instala dependencias: solo sirve al stack local. Las funciones corren la imagen del target lambda, que lleva la aplicación y sus dependencias de producción.
- Las herramientas de desarrollo (make stress, make chaos y las revisiones) y el seeder de la demo se niegan a correr si APP_ENV no es local ni testing. Una ejecución interrumpida deja su empresa sintética hasta el siguiente make seed.

## Nube emulada

- El emulador local de AWS es Floci 2.5.0. Acepta los recursos de las plantillas y crea cada uno de verdad: un tipo no soportado hace fallar su stack (FLOCI_SERVICES_CLOUDFORMATION_ALLOW_STUB_UNSUPPORTED_RESOURCE_TYPES=false).
- El emulador no evalúa ninguna política de IAM, de recursos ni de llaves. Por eso, esto solo se revisa sobre las plantillas sintetizadas, con las pruebas de la app de CDK y con cdk-nag:
  - los roles de mínimo privilegio de las funciones
  - las políticas administradas
  - las políticas de solo TLS del tema, de las colas y de los buckets
  - que el tema sea el único que les envía mensajes a las colas
  - la política del repositorio de ECR
  - el contexto de cifrado de la llave de firma
- Las funciones llegan al emulador por HTTP plano, que esas políticas rechazan en AWS.
- El emulador acepta estos ajustes, pero no los aplica:
  - la llave de cifrado del tema
  - los registros de acceso al servidor de los buckets
  - las clases de almacenamiento y las expiraciones de sus reglas de ciclo de vida
  - la política de ciclo de vida del repositorio de ECR y su escaneo en cada push
- SES guarda cada correo en /_aws/ses y no entrega ninguno. make cloud-check lee ese endpoint del emulador, y make cloud-demo lo vacía. La dirección del remitente queda verificada de inmediato, y DKIM, SPF y DMARC no se emulan.
- Un entorno a la vez: el stack local y la nube emulada usan los mismos puertos. Comparten el servidor MySQL, cada uno con su propia base de datos. La nube emulada sirve desde su borde la compilación de producción de la aplicación web en lugar del servidor de desarrollo, y cada make cloud-up la vuelve a compilar.
- Un solo simulador de la DIAN sirve a los dos, y mantiene aparte los registros de cada uno: los contribuyentes, los documentos procesados, los modos de falla, el registro de actividad y el receptor de webhooks. El stack local usa sus rutas raíz (`http://simulator:8602/...`). La nube emulada usa las mismas rutas bajo `/cloud` (`http://simulator:8602/cloud/...`).
  - Así, cambiar entre make up y make cloud-up nunca hace que el simulador rechace un documento nuevo con la regla 90, ni obliga a recargar los datos de ejemplo.
  - make seed borra en el simulador solo los registros del stack local, y make cloud-demo solo los de la nube. make clean elimina el volumen del simulador, así que borra los dos.
- Las identidades de base de datos de la etapa local reciben contraseñas generadas en el Secrets Manager del emulador. make cloud-up crea la identidad de migraciones a partir de su secreto, con el usuario root de MySQL, igual que Aurora crea su usuario maestro. La función de consola crea la identidad de la aplicación.
- El token de administración del simulador de la DIAN, que es aleatorio en cada clon y está en backend/.env, llega a las funciones de la etapa local como variable de entorno. Por eso está en su configuración, y en la plantilla de su stack en el emulador. El simulador es una herramienta de desarrollo, y la etapa de producción no tiene un token así.
- Las funciones reciben del emulador las credenciales temporales de sus roles, y el emulador acepta cualquiera.
- Las funciones de la nube emulada se conectan al servidor MySQL de Compose por TLS 1.3, y las identidades se rechazan sin TLS. Pero el certificado del servidor no se verifica: el servidor MySQL de Compose firma el suyo, para un nombre distinto de db. La etapa de producción lo verifica contra el paquete de certificados de RDS que trae la imagen (DB_SSL_VERIFY_SERVER_CERT), algo que solo una cuenta real puede probar.
- El emulador no puede correr las funciones de rotación de AWS, que son aplicaciones del Serverless Application Repository publicadas desde cuentas de AWS. Por eso los secretos de las identidades de la etapa local no tienen programación de rotación, algo que cdk-nag reconoce.
- make cloud-check hace las veces de la rotación con usuarios alternos. La función de consola, con la identidad de migraciones como superusuario, hace los pasos de la rotación:
  - una contraseña nueva en el usuario que no es el actual, que crea la primera vez, con los privilegios de la identidad
  - una prueba de las credenciales nuevas
  - una nueva versión actual del secreto
- La función de consola escribe el secreto a través del emulador, que no evalúa IAM. Su rol no tiene permiso para escribir un secreto; en AWS, eso lo hacen las funciones de rotación.
- Floci 2.5.0 no aplica un SecretStringTemplate construido con funciones intrínsecas: guardó solo la contraseña generada. Por eso el secreto de la identidad de la aplicación de la etapa local no nombra ningún masterarn. Solo la función de rotación de AWS lee ese campo, y la etapa de producción lo conserva.
- Las funciones corren como funciones Lambda, en contenedores que el emulador arranca a través del socket de Docker. Solo el perfil cloud monta ese socket.
- Todo lo que crea el emulador lleva el nombre del proyecto y la etiqueta dian-einvoice=floci, que make cloud-down y make up usan para eliminarlo:
  - Los contenedores de las funciones se llaman `dian-einvoice-dian-einvoice-<function>-<id>`: el prefijo, luego el espacio de nombres del emulador y luego el nombre de la función.
  - El registro de imágenes que hay detrás de su ECR se llama floci-aws-dian-einvoice-ecr, y su consola web, floci-aws-dian-einvoice-console. El volumen del registro de imágenes es floci-aws-dian-einvoice-ecr-registry-data.
  - Estos nombres empiezan por floci-aws- porque el emulador les pone ese prefijo a los contenedores y a los volúmenes que arranca además de las funciones.
- El emulador mantiene como máximo 8 contenedores de funciones inactivos en total (2 por función), y elimina uno después de 5 minutos sin una invocación. Los que están ocupados no cuentan.
- El registro de imágenes no tiene un límite de memoria de Compose, porque lo arranca el emulador.
- AWS corre una función sobre un sistema de archivos de solo lectura, salvo /tmp, como un usuario que no es dueño de nada. El emulador la corre como un usuario sin privilegios (993:990, FLOCI_SERVICES_LAMBDA_DOCKER_FLAGS) sobre un sistema de archivos en el que se puede escribir. Así, una escritura fuera de /tmp falla por falta de permisos, no porque el sistema de archivos sea de solo lectura. Todos los archivos de la imagen son de root.
- AWS congela un entorno de ejecución apenas la función devuelve su respuesta; el emulador no. Así, el trabajo que quede para después de la respuesta correría aquí, pero en AWS se quedaría a medias. La aplicación no deja ninguno, y una prueba rechaza las llamadas que lo dejarían (docs/es/infrastructure.md).
- Los arranques en frío del emulador no son los de AWS: AWS además descarga primero la imagen de una versión nueva y la guarda en caché. Aquí, un arranque en frío de la API tarda alrededor de 1 s y una respuesta en caliente alrededor de 15 ms. make cloud-check reporta los dos como cifras del emulador.
- Antes de arrancar un contenedor, el emulador revisa que el usuario de Lambda pueda entrar a cada directorio del directorio de trabajo. Lo hace copiando el directorio fuera del contenedor con el endpoint archive de la API de Docker. Para /var/task, el directorio de la aplicación (unos 125 MB), eso tardó de 8 a 9 s. Por eso la imagen trabaja desde /, y nada depende de su directorio de trabajo.
- Floci 2.5.0 espera el tiempo de visibilidad de la cola (60 s) antes de devolver un registro que una función reportó como fallido, sin importar la visibilidad que haya fijado la función. AWS respeta la visibilidad que fijó la función. Así que, aquí:
  - un mensaje ilegible, que debería volver un segundo después, llega a la cola de mensajes fallidos tras sus 10 recepciones en unos 10 minutos, en vez de segundos
  - un registro devuelto antes del plazo de su invocación vuelve después de un minuto, en vez de enseguida
  - un reintento previsto para dentro de 2 a 144 s (DIAN_RETRY_DELAYS y su jitter) corre de uno a tres minutos después, y gasta una recepción por minuto
- Por eso make cloud-check se detiene en la segunda recepción de su mensaje ilegible, y vacía la cola de la cadena.
- El emulador corrigió esto en su rama principal el 19 de noviembre de 2026 (el pull request #9003 de floci-io/floci), después de la 2.5.0, y todavía ninguna versión trae la corrección. make cloud-check lee la versión del emulador y, en una versión posterior, vuelve a esperar a la cola de mensajes fallidos.
- El emulador corre una invocación a la vez por cada mapeo de cola: guarda MaximumConcurrency, pero no lo aplica. Por eso la cadena de make cloud-check corre un lote a la vez, y el tiempo entre la emisión y la entrega que reporta es el de un solo consumidor. La concurrencia reservada sí se aplica.
- Detrás de API Gateway y Lambda, una petición lleva como máximo 6 MB, el límite de una invocación síncrona (API Gateway acepta 10). Lo mismo vale para una respuesta, en base64 si es binaria.
  - El archivo más grande que sirve la aplicación, un zip de 2 MB como máximo (la sección 9.1 del anexo), cabe.
  - Subir archivos más grandes necesitaría URL prefirmadas de S3, que la aplicación no usa.
- El emulador sirve API Gateway y CloudFront solo por nombre de host (`<id>.execute-api.localhost.floci.io`, `<id>.cloudfront.localhost.floci.io`). Por eso los puertos de la aplicación web y de la API (8601 y 8600) son de un proxy inverso mínimo delante de la distribución: el servicio cloud-gateway de Compose (nginx). make cloud-up lo apunta a la distribución que desplegó (infra/functions/.env.cloud). En AWS no hay un proxy así.
- Las colas de la etapa local esconden un mensaje durante 60 s: el tiempo de espera de sus consumidores, y el mínimo que acepta Lambda. El emulador devuelve los registros fallidos después del tiempo de visibilidad de su cola (docs/es/infrastructure.md).
- Las colas de la etapa de producción esconden un mensaje durante 360 s, seis veces el tiempo de espera de sus consumidores, como recomienda AWS para evitar duplicados después de un throttling.
- Si se mata una función mientras procesa un mensaje, el mensaje vuelve después de ese tiempo. En el emulador, cuando la invocación falla del todo en vez de devolver un error, el mensaje vuelve después de 120 s. Ese es el tiempo que el emulador esconde cada mensaje que le entrega a una función: el tiempo de espera de la función, los 30 s de FLOCI_SERVICES_LAMBDA_INIT_TIMEOUT_SECONDS y 30 s más.
- Un mensaje programado para más tarde (el reintento de una transmisión o de un webhook) gasta una de las recepciones de su cola mientras espera, invisible. Después de una espera así, a su trabajo le quedan hasta 9 intentos de los 10 que permite maxReceiveCount.
- Un mensaje que SNS no puede entregar termina en la misma cola de mensajes fallidos.
- Un registro que un consumidor no alcanzó a terminar antes del plazo de su invocación vuelve a la cola enseguida, y también gasta una recepción.
- Cada cola tiene un trabajo por tipo de evento. La función consumidora manda un evento a la cola de mensajes fallidos cuando su cola no tiene ningún trabajo para el tipo del evento, o tiene más de uno. Una prueba del backend revisa las suscripciones contra las rutas por las que filtra la app de CDK.
- Los secretos se leen una vez por entorno de ejecución: la configuración se guarda en caché al arrancar, con ellos. Un secreto rotado llega a los entornos que arrancan después de la rotación. Los que ya están corriendo conservan los valores viejos hasta que Lambda los reemplaza.
- Para la base de datos, esto está previsto por partida doble:
  - La identidad de la aplicación rota con usuarios alternos, así que las credenciales de una función en caliente siguen siendo válidas hasta la rotación que viene después de la próxima.
  - El conector vuelve a leer el secreto de su identidad, una vez, cuando la base de datos rechaza las credenciales.
- La identidad de migraciones, que solo usa la función de consola, rota como usuario único. Eso deja una ventana de segundos, entre el cambio de la contraseña y el cambio del secreto, en la que una conexión falla.
- Las CLI llegan a sus buckets de despliegue por nombre de host, que Compose le da al emulador como alias. Son el bucket de assets de cdk bootstrap y el bucket de despliegues de osls.
- La etapa de producción se sintetiza, se empaqueta y se valida, pero nunca se despliega. Estos valores son de relleno, y los reemplazan los parámetros de despliegue de una cuenta real (docs/es/deployment.md):
  - su cuenta (111111111111)
  - su dominio (app.dian-einvoice.example) y el certificado de ese dominio
  - la dirección de respaldo de las alertas (operators@dian-einvoice.example)
  - el repositorio que la despliega (example-owner/dian-einvoice)
- La plantilla empaquetada sin conexión nombra su imagen con un resumen de relleno (sha256 de ceros). El despliegue lo reemplaza por el resumen de la imagen que subió.
- [cost.md](cost.md) estima lo que costaría la etapa de producción al mes, y dice cuáles de sus valores son supuestos.
- Las pruebas de los adaptadores de AWS y de los handlers de las funciones corren con el AWS SDK respondiendo desde memoria, y con eventos de Lambda de ejemplo. Solo make cloud-check corre las funciones en el emulador, y la integración continua solo lo corre en main.

## Borde

El borde es la distribución de CloudFront que está delante de la aplicación web y de la API (docs/es/infrastructure.md). El emulador sirve la distribución de la etapa local, así que make cloud-check y make cloud-e2e pasan por ella. Aplica los comportamientos de la distribución, la política de cabeceras de respuesta de la aplicación web, la cabecera personalizada del origen y la política de peticiones al origen. También agrega la dirección del cliente a X-Forwarded-For. Lo que no reproduce:

- Sin AWS WAF. El firewall solo existe en la etapa de producción, y se revisa sobre la plantilla sintetizada: las reglas, las anulaciones que solo cuentan, los límites y la asociación. Solo una cuenta real mostraría que sus reglas bloquean lo que deben y dejan pasar a la API (cuerpos de varias líneas, el certificado, clientes sin User-Agent).
- Sin caché y sin compresión: cada petición llega al bucket o a API Gateway. Por eso el Cache-Control de los archivos, los TTL de la política de caché, gzip y Brotli, y una invalidación se revisan sobre la plantilla y sobre las cabeceras de los archivos, no sobre una caché. panel.mjs se salta la invalidación en la etapa local.
- Sin CloudFront Functions, aunque estén asociadas a la distribución. En su lugar, el proxy que tiene delante (cloud-gateway) reescribe las rutas de la aplicación web, con los mismos casos que la función. El código de la función corre en las pruebas de la app de CDK como lo corre CloudFront (un script simple con un handler), no en el runtime de CloudFront.
- El CloudFormation del emulador (Floci 2.5.0) conserva solo una parte de cada comportamiento: su ruta, su origen, su política de protocolo del visitante, su política de cabeceras de respuesta y su compresión. Descarta los métodos que el comportamiento permite y guarda en caché, sus políticas de caché y de peticiones al origen, y sus funciones. A través de él, la API solo recibiría GET y HEAD, sin parámetros de consulta ni cookies.
- make cloud-up le da a la distribución esos ajustes como los declara su plantilla desplegada, a través de la API de CloudFront del emulador (infra/functions/scripts/edge.mjs reconcile). Este arreglo para el emulador:
  - no crea nada, y no cambia nada que la plantilla no declare
  - se niega a correr contra cualquier cosa que no sea el emulador
  - es idempotente
  - imprime, por comportamiento, cada ajuste que tuvo que devolverle
  - cuando se corre otra vez sin nada nuevo, dice que conserva lo que le dio antes (la distribución cambió después de que CloudFormation la desplegó por última vez)
  - cuando la distribución, tal como la desplegó CloudFormation, ya tiene todos los ajustes, dice que no hubo que aplicar nada y que el paso se puede retirar
- make cloud-check revisa que la distribución tenga lo que declara su plantilla. AWS aplica la plantilla tal como está.
- HTTP plano: el emulador sirve la distribución por HTTP, diga lo que diga su política de protocolo del visitante. Por eso la redirección a HTTPS, HTTP/2 y HTTP/3, TLS 1.2 y el certificado del dominio solo se revisan sobre la plantilla. HSTS llega al navegador por HTTP en localhost, donde el navegador lo ignora.
- El emulador reemplaza el User-Agent de una petición por el de CloudFront cuando la política de peticiones al origen no lo nombra.
- El emulador no escribe registros de acceso en el bucket de registros, ni les aplica ciclo de vida.
- El emulador no evalúa ninguna política de bucket, así que el control de acceso al origen (solo la distribución lee el bucket) se revisa sobre la plantilla.
- El valor del secreto del borde llega a la distribución de la etapa local como un parámetro de despliegue, porque el emulador solo expande las referencias dinámicas a Secrets Manager en las credenciales de RDS.
  - make cloud-up se lo pasa a la CLI de CDK por un pipe, así que está en los parámetros del stack en el emulador (NoEcho lo esconde de DescribeStacks).
  - Como en AWS, también está en la configuración de la distribución, que muestra las cabeceras personalizadas tal como son.
  - La etapa de producción lo resuelve desde Secrets Manager.
- Rotar el secreto del borde es un procedimiento (docs/es/infrastructure.md) que make cloud-check no corre.
- El verificador de origen corre en Node.js 24, el runtime de Lambda más nuevo para el que el emulador tiene imagen. Su primera llamada descarga la imagen del runtime de Node.js de Lambda (public.ecr.aws/lambda/nodejs). Usa el AWS SDK de ese runtime, que AWS actualiza por su cuenta.

Lo que solo una cuenta real mostraría:

- que CloudFront reemplaza una cabecera X-Origin-Verify que mande un visitante (está documentado para las cabeceras personalizadas de un origen; el emulador sí la reemplaza)
- las ubicaciones de borde de Suramérica en la clase de precio
- la política TLS TLSv1.2_2021, con el certificado de ACM
- HTTP/3
- las reglas administradas de AWS WAF con tráfico real, y sus límites por dirección
- la caché del autorizador de API Gateway (5 minutos por valor)
- rotar el secreto del borde mientras la distribución se despliega
- los registros estándar escritos con ACL
- los registros DNS del dominio: el alias de la distribución, la validación del certificado y los registros DKIM, MAIL FROM, SPF y DMARC de SES, que publica el dueño del dominio (aquí no se administra ninguna zona alojada)

## Observabilidad

Los registros estructurados, las métricas, las alarmas, el tablero, las consultas guardadas y el notificador (docs/es/observability.md) se despliegan en el emulador, y make cloud-check los pone a prueba. Revisa que:

- cada línea que escriben las funciones sea un JSON que cumpla el esquema
- la consulta guardada siga una factura por su id de correlación
- ningún registro tenga un secreto
- un filtro de métricas produzca su métrica
- un estado forzado en una alarma llegue al receptor local del chat a través del notificador

make cloud-alarm-check hace que el emulador evalúe alarmas de verdad, en tres o cuatro minutos:

- un mensaje en la cola de mensajes fallidos de la cadena pasa su alarma a ALARM y de vuelta a OK, y cada cambio llega al chat
- una notificación que el chat rechaza va a la cola de mensajes fallidos del notificador, cuya alarma llega al chat

Lo que el emulador (Floci 2.5.0) no reproduce, o solo reproduce con ayuda:

- **Alarmas escritas como consultas de datos de métricas.** El CloudFormation del emulador crea una alarma cuya métrica es una consulta de datos de métricas sin sus métricas, así que la alarma nunca podría evaluarse. Esas consultas son las Metrics de una alarma de métricas calculadas, o de una métrica con etiqueta. Las alarmas sobre una sola métrica se escriben con la métrica misma, que el emulador sí conserva.
  - make cloud-up les da a las tres alarmas de métricas calculadas (function-errors, function-throttles, dian-error-rate) las métricas que declara su plantilla, a través de la API de CloudWatch (infra/functions/scripts/observability.mjs reconcile).
  - Esta reconciliación funciona bajo las mismas condiciones que la del borde. Se niega a correr contra cualquier cosa que no sea el emulador. Es idempotente: solo devuelve lo que falta, y no toca una alarma que ya tiene sus métricas.
  - Dice qué devolvió, y qué alarmas conservan lo que les dio antes (su configuración cambió después de la última vez que CloudFormation las creó o las actualizó).
  - Cuando todas las alarmas tienen sus métricas tal como las desplegó CloudFormation, dice que el emulador dejó de descartarlas y que el paso se puede retirar.
  - AWS aplica la plantilla tal como está.
- **Sin evaluación de métricas calculadas ni acciones de alarmas compuestas.** Las tres alarmas de métricas calculadas se conservan, pero nunca se evalúan. Se quedan en INSUFFICIENT_DATA, como lo mostrarían sus notificaciones. La alarma compuesta service-degraded solo existe en la etapa de producción, porque el emulador no corre ninguna de sus acciones. Las dos cosas se revisan sobre las plantillas (las pruebas de la app de CDK, cdk-nag, cfn-lint).
- **Sin métricas nativas de Lambda, API Gateway, CloudFront, AWS WAF, Aurora ni del NAT gateway.** El emulador publica cada minuto las métricas de SQS, y las de los filtros de métricas de los registros, y ninguna otra. Por eso las alarmas de errores, throttling, 5xx y latencia se despliegan pero se quedan sin datos (la falta de datos no cuenta como superar el umbral), y sus widgets quedan vacíos. Solo una cuenta real mostraría que se disparan con tráfico real.
- **Sin consultas guardadas a través de CloudFormation.** El CloudFormation del emulador no puede crear AWS::Logs::QueryDefinition. Por eso la etapa local declara las cuatro consultas en los metadatos de la plantilla del stack observability (lib/stage.ts, SavedQueries), y make cloud-up las guarda desde ahí con la API de Logs. La etapa de producción las declara como recursos.
  - Guardarlas es parte del mismo paso, con las mismas condiciones. Solo guarda una consulta que falte o que difiera de la plantilla, por su nombre, y dice cuáles.
  - El emulador valida cualquier plantilla, así que solo un despliegue dice si una versión posterior crea las consultas. En una versión posterior a la 2.5.0, el paso lo dice, y dice que esta parte se puede retirar cuando la etapa declare las consultas como recursos.
- **Logs Insights solo evalúa fields, filter, sort, dedup y limit.** La consulta follow-a-journey está escrita con esos comandos, y make cloud-check la corre. Las consultas errors-by-function y dian-rejections-by-rule usan stats, y no se pudieron probar. La consulta slow-requests se guarda, pero no se corre.
- **Los tableros se guardan, no se dibujan.** El tablero se revisa sobre su plantilla, y una prueba ata los límites que dibuja a la concurrencia reservada de las funciones.
- **Sin registros del sistema de Lambda ni registros de acceso de API Gateway.** El emulador escribe la salida de cada contenedor en su grupo de registros, y nada propio. No queda constancia del inicio, el fin o el reporte de una invocación, ni en JSON ni de ninguna otra forma. El grupo de registros de acceso de API Gateway se queda vacío. LogFormat JSON, el formato del registro de acceso y los registros de AWS WAF (producción) se revisan sobre las plantillas.
- **El runtime de un contenedor imprime sus propias líneas cuando el emulador elimina el contenedor.** El emulador elimina un contenedor inactivo después de 5 minutos sin una invocación, o en un nuevo despliegue. El runtime de la función pierde entonces su endpoint de invocación. Imprime el error en su propio formato, como las últimas líneas de su flujo de registros (el formato del runtime de Node.js, y el de Bref para PHP).
  - make cloud-check aparta esas líneas, desde la primera hasta el final de su flujo, y dice cuántas son. Todas las demás líneas deben ser JSON que cumpla el esquema.
  - AWS congela y elimina un sandbox sin esas líneas.
- **Aceptado, no aplicado:**
  - la llave de KMS de los temas de las alarmas y su política (el emulador ni siquiera la reporta)
  - las políticas de los temas y de la cola de mensajes fallidos del notificador
  - la suscripción por correo del tema de respaldo, que no envía ningún correo y se queda sin confirmar
- Los reintentos asíncronos de Lambda y el destino de fallas del notificador (para una invocación que falla del todo) no se prueban. make cloud-alarm-check lleva a su cola de mensajes fallidos una notificación que el chat rechaza, de la misma forma en que lo hace el propio notificador.
- **El formato de métricas integradas** no lo documenta el emulador, y no se usa (docs/es/observability.md).
- Ningún endpoint recibe los reportes de violaciones de la Content-Security-Policy, así que una violación en un navegador no llega a ningún registro. make cloud-e2e falla con la primera que ve.
- Los objetivos de servicio están escritos, y las alarmas los defienden contra un consumo rápido de su presupuesto de errores. Todavía nada mide cada uno a lo largo de sus 30 días.

## Operación y entrega

Los límites de los runbooks, del simulacro de incidentes y del pipeline de despliegue (docs/es/runbooks, docs/es/deployment.md):

- **El despliegue nunca ha corrido.** El flujo de trabajo .github/workflows/deploy.yml nunca se ejecutó. actionlint lo valida, y los pasos que corre son los mismos que make cloud-up corre contra el emulador.
  - El rol de despliegue, su relación de confianza por OpenID Connect y el rol con el que CloudFormation despliega las funciones están escritos a partir de la documentación de AWS, de la CLI de CDK y de osls. Lo mismo vale para el push a ECR, el orden de un primer despliegue y cada forma de revertir.
  - Todo esto se revisa sin conexión (síntesis, aserciones, cdk-nag, cfn-lint), y nunca se probó con credenciales reales. docs/es/deployment.md lista lo que nunca se verificó.
  - La política de ejecución que CloudFormation recibe del bootstrap de CDK la elige el dueño de la cuenta: AdministratorAccess, a menos que dé otra. Este repositorio no escribe ninguna más restringida.
  - Las acciones del flujo de trabajo están fijadas a su versión mayor, como en la integración continua, no a un commit.
- **Los tiempos del simulacro son los del emulador** (Floci 2.5.0 en esta máquina) y los de la etapa local.
  - Las transmisiones de la etapa local se reintentan dos veces (2 y 5 s) en vez de cinco (2, 5, 15, 45 y 120 s).
  - La razón: el emulador devuelve un reintento programado después del tiempo de visibilidad de 60 s de su cola, sea cual sea su demora. Así, cinco reintentos tardarían seis minutos, y el simulacro más de diez.
  - En AWS, una transmisión se rinde después de unos tres minutos, así que los mensajes fallidos llegan primero. La alarma de la DIAN, una alarma de métricas calculadas que el emulador nunca evalúa, necesita 10 minutos de fallas y se dispararía después de ellos.
  - El reporte (docs/es/runbooks/drill-report.md) cubre una sola ejecución.
- **Los comandos para una cuenta real** que dan los runbooks y la guía de despliegue se revisaron sin conexión, contra los modelos del AWS SDK, para confirmar que son operaciones de la AWS CLI. Nunca se corrieron. En el emulador, solo corrieron los objetivos de make.
- El emulador corre StartMessageMoveTask y ListMessageMoveTasks. Sin un destino, solo mueve los mensajes que movió su política de redrive, y deja la tarea activa por los demás mensajes, lo que hace que se rechace la siguiente tarea de la cola. Por eso los runbooks y make cloud-redrive siempre nombran la cola del canal como destino, algo que AWS también acepta.
- El ECR del emulador lista cada imagen como subida en el momento en que se lista. Por eso las imágenes recientes de make cloud-deploy STEP=versions salen ahí sin un orden que tenga sentido.
- Un paso que se rindió aparece como fallido para su empresa hasta que un operador devuelve su mensaje fallido a la cola o la empresa presiona Reintentar ahora. Ningún estado dice que está esperando a la DIAN, y no hay redrive automático cuando la DIAN vuelve.
- El procedimiento de contingencia de la DIAN para caídas largas (facturas emitidas sin su validación y enviadas después) no está construido.
- Un paso que se rindió vuelve a empezar cuando su mensaje regresa, si el documento sigue fallido en ese paso de su ejecución. SQS entrega al menos una vez, así que un duplicado poco común de un mensaje así, entregado justo después de que el paso se rindió, arranca el paso una vez más. La transmisión consulta primero GetStatus, y un número repetido recibe una respuesta con la regla 90, así que nada se valida dos veces.
- La aplicación web no le muestra a su usuario el id de correlación de un error. Una integración lo lee de X-Correlation-Id o del cuerpo del problema; un usuario de la aplicación web lo lee en las herramientas de desarrollo del navegador.
- make migrations-check lee en up() los borrados y los cambios de nombre de columnas y tablas. Un cambio de tipo de columna (change()) y una migración de datos quedan para la revisión del código. Una versión es una etiqueta vMAJOR.MINOR.PATCH: por ahora, v1.0.0 y v1.0.1.
- No hay página de estado para las empresas, ni turnos de guardia. Las alarmas llegan al chat y al correo de respaldo.

## Red y base de datos de producción

Los stacks network y database solo existen en la etapa de producción, y nunca se despliegan, ni siquiera en el emulador. Se prueban sobre lo que produce la síntesis (docs/es/infrastructure.md). Lo que solo una cuenta real podría mostrar:

- Que AWS acepta las plantillas tal como están escritas:
  - Aurora MySQL 8.4.8 Serverless v2, con una instancia de lectura en el nivel de promoción 1. La validación de esquemas de CDK y cfn-lint conocen Aurora MySQL 8.4 hasta la 8.4.8; la 8.4.10 (del 26 de octubre de 2026) es más nueva que esas dos herramientas.
  - Los parámetros del clúster (require_secure_transport, tls_version, slow_query_log, server_audit_*).
  - Las exportaciones a grupos de registros creados de antemano.
  - La CA de las instancias.
  - Las direcciones IP elásticas que se les asignan a los NAT gateways.
  - Los registros de flujo, a través de su rol.
  - Los endpoints y su DNS privado.
  - Las referencias entre los stacks (Fn::GetStackOutput, las referencias débiles de CDK).
- Las cifras tomadas de la documentación de AWS, sin medir:
  - max_connections de 135 con 4 ACU
  - un failover a la instancia de lectura, normalmente en 30 segundos o menos, y un reemplazo sin instancia de lectura en menos de 10 minutos
  - unos 15 s para reanudar una instancia en pausa
  - de 300 a 1.000 MiB de memoria para la autenticación de IAM
  - los precios
- Los precios están en [cost.md](cost.md#precios), leídos de la AWS Price List API y de las páginas de precios el 22 de noviembre de 2026:
  - una hora de ACU de Aurora Standard cuesta US$ 0,12
  - un NAT gateway cuesta US$ 0,045 la hora y US$ 0,045 por GB
  - un endpoint de interfaz cuesta US$ 0,01 la hora por zona y US$ 0,01 por GB
  - RDS Proxy cuesta US$ 0,015 por hora de ACU, con un mínimo de 8 ACU
- Que las funciones de rotación de AWS:
  - corren en las subredes privadas
  - llegan a la base de datos y a Secrets Manager a través del NAT gateway
  - clonan el usuario de la aplicación con sus privilegios en la primera rotación, y alternan después
- Que la primera rotación de la identidad de migraciones, al crearla, deja el clúster utilizable.
- Que las funciones verifican el certificado del servidor contra el paquete de certificados de RDS, con el nombre del endpoint del clúster.
- Que RDS Proxy, cuando está activo, acepta caching_sha2_password por TLS, y la rotación de usuario único detrás de él.
- Que los grupos de seguridad y las rutas se comportan como dicen las plantillas:
  - solo las funciones, RDS Proxy y las funciones de rotación llegan a la base de datos
  - las subredes aisladas no tienen salida
  - perder un NAT gateway afecta exactamente lo que lista docs/es/infrastructure.md
  - los endpoints de interfaz, cuando están activos, existen en las zonas elegidas (algunos servicios solo los ofrecen en algunas zonas)
- Cómo aguantan las funciones un failover y la pérdida de una zona (la tabla de fallas de docs/es/infrastructure.md).
- La concurrencia reservada frente a la cuota de la cuenta.
- El volumen del registro de auditoría y de los registros de flujo.

## Interfaz

- No se han revisado el uso con teclado, los lectores de pantalla ni el contraste.
- El tema se guarda en localStorage, y el estado de la barra lateral en una cookie. El botón de la cuenta de ejemplo solo existe en el servidor de desarrollo.
- Ctrl+K se captura en todas partes. Las peticiones de búsqueda no se cancelan mientras el usuario escribe. La protección contra salir con cambios sin guardar tiene huecos.

## Pruebas y herramientas

- Las pruebas de integración corren la cadena en el mismo proceso (InlineChain) y mandan el correo al mailer array de Laravel. El relay, los workers de la cola y el tramo SMTP hasta el buzón local los prueban make checks, make e2e y el trabajo stack de la integración continua.
- Cada ejecución de make e2e y de make cloud-e2e agrega dos facturas a Demo Comercial SAS, una de ellas con su pago. Cada ejecución de make docs-examples agrega cuatro facturas, dos contactos y dos ítems: una factura por cada README, y un contacto, un ítem y una factura por cada guía de la API.
- La visita de make cloud-e2e pasa por cada ruta y abre los diálogos principales sin enviarlos. Solo el recorrido de la factura cubre las acciones que la visita no hace (enviar un formulario, emitir desde el editor).
- La prueba de punta a punta abre la aplicación en http://localhost:8601 dentro de su contenedor, el origen que la API acepta para las sesiones. Sus archivos siguen la configuración de Prettier de la aplicación web, pero ningún linter corre sobre ellos.
- Las pruebas del simulador usan documentos sintéticos de la demo como fixtures, con el reloj fijo dentro de la vigencia de su certificado de prueba.
- Ninguna prueba cubre el tope de 64 KB de las respuestas de los webhooks, ni los límites de la vista previa y de la búsqueda. El código QR del PDF se prueba como el texto que codifica (QrContentTest), no leyéndolo de vuelta desde la imagen, lo que necesitaría un decodificador de QR.
- make e2e y make cloud-e2e miden la disposición en el DOM (e2e/tests/layout.spec.ts), solo con los datos de ejemplo. Cubren cada ruta, el tablero incluido, y los diálogos principales, a 1280×720, 1440×900 y 1920×1080, en inglés y en español. Nada puede salirse de su tarjeta, su diálogo, su contenedor de tabla o su panel, ni quedar cortado donde el lector no pueda desplazarse hasta verlo; ningún diálogo puede ser más grande que la ventana; y cada lista cabe en 1440×900 o más sin desplazamiento horizontal. Dos elementos pasan a propósito, cada uno con su razón en la prueba: los círculos decorativos de las pantallas de inicio de sesión y el riel de la barra lateral. Las ventanas de menos de 1280 px no se miden. Un nombre más largo que los de la demo se corta con puntos suspensivos, pero un monto o una etiqueta más largos todavía podrían empujar una tabla fuera de su caja.
- El simulador de la DIAN mantiene los registros del stack local aparte de los de la nube emulada ([Nube emulada](#nube-emulada)). Aun así, make cloud-e2e corre primero una salvaguarda (cloud:check demo, en la función de consola). La salvaguarda revisa que los registros de la nube no tengan ningún documento de las empresas de la demo con un número que la base de datos de la nube aún no haya emitido.
  - Un documento así tiene un número que tomarían los siguientes documentos de la nube, y el simulador los rechazaría con la regla 90.
  - El objetivo de make se detiene de inmediato y dice que hay que correr make cloud-demo. No lo repara por su cuenta, porque eso implica volver a cargar los datos de ejemplo.
- make test-tools prueba el codificador de imágenes de make screenshots (e2e/screenshots/encode.mjs). Las pruebas leen de vuelta su salida con decodificadores de PNG, LZW y GIF escritos aparte del codificador, en el mismo archivo de prueba. Ninguna librería de imágenes la revisa.
- PHPStan corre con un solo worker en el contenedor api-analyse (2 GB), porque las formas de tipos del AWS SDK ocupan alrededor de 1,4 GB.
- PHPUnit corre en un contenedor propio, api-test (1,5 GB). Las pruebas de concurrencia lo bifurcan en hasta 8 procesos, y cada proceso copia cada página que escribe. El pico del contenedor ronda los 380 MiB, y make test-backend imprime el pico y el límite al final de cada ejecución.
- checkUnknownProps y checkUnknownEvents de vue-tsc siguen apagados: 390 atributos y listeners pasan directo a los wrappers de shadcn-vue y reka-ui.
- Las reglas de ESLint que están apagadas llevan su razón en eslint.config.ts.
- Scramble muestra las reglas de creación para los cuerpos PATCH de los impuestos y las retenciones.
- Scramble da processing como el único valor del filtro electronic_status de las listas de documentos. Su descripción nombra los demás valores.
- Scramble guarda estado entre dos documentos que arma en un mismo proceso, y el segundo pierde parámetros. Cada petición arma uno, y también cada exportación de make openapi. ApiReferenceTest exporta las referencias en un proceso aparte.
- TypeScript está fijado en 6.x, y pnpm corre con una lista de permitidos para los scripts de construcción.

## La documentación en dos idiomas

- Los documentos en español son traducciones, y el inglés es la fuente. Si los dos no coinciden, vale el inglés.
- make docs-check mantiene cada traducción a la par de su original y revisa lo que no es prosa. Cada traducción lleva el SHA-256 de su original y conserva su código, su código en línea, sus enlaces, sus cifras y su estructura. También fallan un párrafo que se quedó en inglés y un término que el glosario reemplaza.
- No juzga la prosa. Que una frase suene natural, o que diga justo lo que dice su original, depende de la revisión de quien la escribe.
- make docs-stamp sella una traducción cuyo código, enlaces, cifras y estructura coinciden con su original. Una frase del original que solo cambió de palabras pasa, aunque su traducción no la haya seguido.
- La revisión del inglés que quedó sin traducir cuenta palabras comunes del inglés y del español en cada párrafo. Una frase corta, o una hecha sobre todo de nombres, puede pasarla en inglés.
- Algunas cosas solo están en inglés:
  - el código y sus comentarios, y los mensajes de los commits;
  - los nombres de los campos, los eventos, las alarmas y las funciones;
  - los registros y la salida de los comandos, make help incluido;
  - las descripciones de las alarmas, el tablero de operación y los mensajes del notificador;
  - los nombres de los trabajos de la integración continua;
  - el texto legal de LICENSE.
- La referencia de la API está en los dos idiomas: http://localhost:8600/docs/api/es y openapi.es.json en español, http://localhost:8600/docs/api y openapi.json en inglés. make docs-check mantiene la española a la par de la inglesa (e2e/docs/reference.mjs).
  - Sus claves, nombres y valores siguen en inglés, por estándar técnico: las rutas, el operationId, los nombres de parámetros, campos y esquemas, los valores de los enums, los ejemplos, los códigos de estado, los nombres de las cabeceras y los tipos de medio.
  - Los rótulos de su visor también siguen en inglés: Stoplight Elements, de un tercero, no trae español.
- Cada alarma nombra su runbook en inglés (docs/runbooks/<name>.md). La línea de cambio de idioma del runbook lleva a su traducción al español.
- NOTICE.es.txt traduce NOTICE solo como referencia. NOTICE es el archivo que vale, y el archivo en español cita los avisos de terceros con sus palabras originales.

## Datos y demo

- Cada persona, empresa, documento y pago de la demo es sintético. Los municipios vienen de la DIVIPOLA (datos.gov.co), con la mayúscula inicial de sus nombres puesta de forma automática. Las direcciones de la demo parecen reales.
- La historia de la demo cuenta hacia atrás desde el día en que se corre, con el tiempo de prueba de Carbon, mientras que el simulador pone fechas reales.
- Las capturas y los GIF del README (make screenshots) muestran el día en que transcurre la historia de la demo, el jueves 19 de noviembre de 2026.
  - Mientras corre el script, el backend y el simulador corren con un reloj movido a esa tarde (scripts/screenshots/compose.yaml), y el navegador también. Así, los datos de ejemplo, las fechas en pantalla y las respuestas del simulador coinciden, sea cual sea el día en que corra el script.
  - Mailpit y MySQL conservan el reloj de la máquina. El script vuelve a cargar los datos de ejemplo, como hace make seed.
  - Dos ejecuciones seguidas dan las mismas imágenes, salvo lo que cada carga de los datos de ejemplo saca al azar. Esos valores son el número de serie del certificado de prueba, los identificadores de las llaves de API de la demo, el id de la empresa en la dirección del receptor de webhooks y el jitter de los reintentos de la caída. Las diferencias tocan unas pocas décimas del uno por ciento de los píxeles, en cuatro capturas.
- Cada captura se corta donde el script encuentra el final de una tarjeta o de una fila de tabla, así que sus alturas varían.
- La captura de la factura recuperada empieza con una entrada completa de la línea de tiempo, y no con la parte de arriba de la tarjeta. Una línea de tiempo con seis intentos fallidos, el reintento y la entrega es más alta que una pantalla.
- En los GIF, el fondo de un diálogo es un tono plano en lugar del desenfoque de la aplicación, que sale a manchas con los 256 colores de un GIF. La aplicación y las capturas PNG conservan el desenfoque.
- make screenshots corre su navegador en inglés británico para docs/assets y en español de Colombia para docs/assets/es, así que el campo de fecha nativo muestra día, mes y año en los dos.
- Los impuestos y las retenciones predeterminados de una empresa se nombran con la misma notación de las tarifas en pantalla, como "IVA 19 %" y "ReteFuente 2,5 % (compras)". Una empresa creada por una versión anterior conserva sus nombres anteriores ("IVA 19%"), que un propietario puede editar. make seed carga los datos de ejemplo con los nombres actuales.
- La caja de herramientas de la DIAN se queda fuera de git, y la mayoría de sus ejemplos nombran empresas reales. make dian-toolbox la descarga, con su SHA-256, cuando make dian-schematron la necesita. Los archivos de terceros que el repositorio sí guarda, como los esquemas oficiales, están listados en NOTICE.
