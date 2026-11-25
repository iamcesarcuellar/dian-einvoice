# Estimación de costos

[English](../cost.md) · **Español**

<!-- Generado por make cost (scripts/cost/estimate.mjs) a partir de scripts/cost/prices.json y scripts/cost/usage.json. No lo edites: make docs-check falla cuando este archivo no coincide con esos dos archivos. -->

Lo que costaría al mes la arquitectura de producción si se desplegara en AWS en us-east-1 (US East, N. Virginia), a precios bajo demanda consultados el 22 de noviembre de 2026.

> **Una estimación, no una factura.** La etapa de producción nunca se desplegó: se sintetiza y se valida sin conexión ([infrastructure.md](infrastructure.md)), y nada de esto se le cobró nunca a una cuenta de AWS. Los volúmenes por factura salen de ejecuciones de la nube emulada, las duraciones y el tráfico son supuestos, y cada uno se declara [más abajo](#supuestos). No incluye los impuestos, los planes de soporte ni la cuenta de AWS en sí.

Cómo se hace: `scripts/cost/estimate.mjs`, un script sin dependencias, calcula cada tabla de esta página a partir de dos archivos: [`scripts/cost/prices.json`](../../scripts/cost/prices.json) (cada precio unitario con su página oficial, el archivo de la AWS Price List API de donde se leyó y la fecha) y [`scripts/cost/usage.json`](../../scripts/cost/usage.json) (cada supuesto con su base y su fuente). `make cost` vuelve a escribir esta página y su versión en inglés, y `make docs-check`, parte de `make lint`, falla cuando alguna de las dos no coincide con los dos archivos.

## Resumen

El costo fijo es lo que cuesta el mes sin ninguna factura, y el costo variable es el resto. El almacenamiento se calcula para el mes 12 con un volumen estable, así que guarda 12 meses de documentos, filas de la base de datos y registros. La capa gratuita queda por fuera, salvo en la última columna. Esa columna resta los montos siempre gratis, que la propia Price List cotiza en US$ 0: Lambda, SQS, SNS, KMS, CloudWatch, CloudFront, EventBridge Scheduler, el almacenamiento de DynamoDB y 100 GB de transferencia de datos de salida. No cuenta los créditos ni las ofertas de 12 meses de una cuenta nueva.

| Escenario | Facturas al mes | Fijo | Variable | Total | Por cada 1.000 facturas | Total con la capa siempre gratis |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Sin facturas | 0 | US$ 153,55 | US$ 0,00 | **US$ 153,55** | — | US$ 145,54 |
| 10.000 facturas | 10.000 | US$ 153,55 | US$ 4,28 | **US$ 157,83** | US$ 15,78 | US$ 148,21 |
| 100.000 facturas | 100.000 | US$ 153,55 | US$ 86,62 | **US$ 240,16** | US$ 2,40 | US$ 220,58 |
| 1.000.000 facturas | 1.000.000 | US$ 153,55 | US$ 603,37 | **US$ 756,92** | US$ 0,76 | US$ 709,40 |

Sin ninguna factura, AWS cobra la capacidad mínima de la base de datos en dos instancias, el NAT gateway, el firewall, las llaves, los secretos y la observabilidad: US$ 153,55 al mes, o US$ 145,54 con la capa siempre gratis.

## Por servicio

El costo de cada servicio en cada escenario, sin la capa gratuita.

| Servicio | Sin facturas | 10.000 facturas | 100.000 facturas | 1.000.000 facturas |
| --- | ---: | ---: | ---: | ---: |
| Aurora MySQL Serverless v2 | US$ 87,70 | US$ 88,19 | US$ 136,39 | US$ 311,76 |
| Lambda | US$ 0,25 | US$ 1,30 | US$ 10,72 | US$ 105,00 |
| CloudFront | US$ 0,00 | US$ 0,26 | US$ 2,57 | US$ 25,72 |
| AWS WAF | US$ 10,00 | US$ 10,03 | US$ 10,29 | US$ 12,88 |
| API Gateway | US$ 0,00 | US$ 0,04 | US$ 0,44 | US$ 4,40 |
| NAT gateway e internet | US$ 36,50 | US$ 36,61 | US$ 37,56 | US$ 47,07 |
| SNS | US$ 0,00 | US$ 0,06 | US$ 0,60 | US$ 6,00 |
| SQS | US$ 0,53 | US$ 0,68 | US$ 2,09 | US$ 16,13 |
| EventBridge Scheduler | US$ 0,09 | US$ 0,09 | US$ 0,09 | US$ 0,09 |
| S3 | US$ 0,00 | US$ 0,69 | US$ 6,87 | US$ 68,69 |
| DynamoDB | US$ 0,11 | US$ 0,14 | US$ 0,44 | US$ 3,41 |
| ECR | US$ 0,24 | US$ 0,24 | US$ 0,24 | US$ 0,24 |
| KMS | US$ 3,32 | US$ 3,38 | US$ 3,92 | US$ 9,32 |
| Secrets Manager | US$ 2,01 | US$ 2,03 | US$ 2,18 | US$ 3,75 |
| SES | US$ 0,00 | US$ 1,06 | US$ 10,55 | US$ 105,51 |
| Métricas y alarmas de CloudWatch | US$ 12,60 | US$ 12,60 | US$ 12,60 | US$ 12,60 |
| CloudWatch Logs | US$ 0,21 | US$ 0,45 | US$ 2,63 | US$ 24,36 |
| **Total** | **US$ 153,55** | **US$ 157,83** | **US$ 240,16** | **US$ 756,92** |

### Las cantidades de cada línea

La cantidad de cada precio en cada escenario; el precio unitario está en [Precios](#precios).

| Servicio | Precio | Sin facturas | 10.000 facturas | 100.000 facturas | 1.000.000 facturas |
| --- | --- | ---: | ---: | ---: | ---: |
| Aurora MySQL Serverless v2 | Aurora MySQL Serverless v2, Aurora Standard, por ACU-hora | 730 | 730 | 1.095 | 2.190 |
| Aurora MySQL Serverless v2 | Almacenamiento de Aurora, Aurora Standard, por GB-mes | 1 | 4,13 | 32,26 | 313,58 |
| Aurora MySQL Serverless v2 | Peticiones de E/S de Aurora, Aurora Standard, por petición de E/S | 0 | 880.000 | 8.800.000 | 88.000.000 |
| Aurora MySQL Serverless v2 | Almacenamiento de copias de seguridad de Aurora MySQL más allá del 100 % del almacenamiento del clúster, por GB-mes | 0 | 0 | 0 | 0 |
| Lambda | Peticiones a Lambda, por petición | 96.360 | 270.360 | 1.836.360 | 17.496.360 |
| Lambda | Duración de Lambda en x86_64, primeros 6 mil millones de GB-segundo, por GB-segundo | 13.818,9 | 74.578,9 | 621.418,9 | 6.089.818,9 |
| CloudFront | Peticiones HTTPS a CloudFront atendidas en Suramérica, por petición HTTPS | 0 | 48.000 | 480.000 | 4.800.000 |
| CloudFront | Peticiones HTTPS a CloudFront atendidas en Estados Unidos, por petición HTTPS | 0 | 0 | 0 | 0 |
| CloudFront | Transferencia de datos de salida de CloudFront en Suramérica, primeros 10 TB, por GB | 0 | 1,37 | 13,75 | 137,48 |
| CloudFront | Transferencia de datos de salida de CloudFront en Estados Unidos, primeros 10 TB, por GB | 0 | 0 | 0 | 0 |
| CloudFront | Invocaciones de CloudFront Functions, por invocación | 0 | 4.000 | 40.000 | 400.000 |
| AWS WAF | Web ACL de AWS WAF, por web ACL-mes | 1 | 1 | 1 | 1 |
| AWS WAF | Regla de AWS WAF, o grupo de reglas agregado a una web ACL, por regla-mes | 5 | 5 | 5 | 5 |
| AWS WAF | Peticiones de AWS WAF, hasta 1.500 WCU, por petición | 0 | 48.000 | 480.000 | 4.800.000 |
| API Gateway | Peticiones a las API HTTP de API Gateway, primeros 300 millones, por petición | 0 | 44.000 | 440.000 | 4.400.000 |
| NAT gateway e internet | NAT gateway, por hora | 730 | 730 | 730 | 730 |
| NAT gateway e internet | Dirección IPv4 pública en uso (la Elastic IP del NAT gateway), por hora | 730 | 730 | 730 | 730 |
| NAT gateway e internet | Datos procesados por un NAT gateway, por GB | 0 | 1,41 | 14,1 | 140,95 |
| NAT gateway e internet | Transferencia de datos de salida a internet desde us-east-1, primeros 10 TB, por GB | 0 | 0,47 | 4,7 | 46,95 |
| SNS | Peticiones de publicación en SNS, después del primer millón, por petición | 0 | 120.000 | 1.200.000 | 12.000.000 |
| SQS | Peticiones a colas estándar de SQS, primeros 100 mil millones, por petición | 1.314.000 | 1.704.000 | 5.214.000 | 40.314.000 |
| EventBridge Scheduler | Invocaciones de EventBridge Scheduler, después de los primeros 14 millones, por invocación | 87.600 | 87.600 | 87.600 | 87.600 |
| S3 | Almacenamiento en S3 Standard, primeros 50 TB, por GB-mes | 0 | 17,61 | 176,06 | 1.760,62 |
| S3 | Peticiones PUT, COPY, POST y LIST a S3, por petición | 0 | 50.000 | 500.000 | 5.000.000 |
| S3 | Peticiones GET y demás peticiones a S3, por petición | 0 | 80.000 | 800.000 | 8.000.000 |
| DynamoDB | Escrituras bajo demanda en DynamoDB, por unidad de petición de escritura | 175.200 | 219.200 | 615.200 | 4.575.200 |
| DynamoDB | Lecturas bajo demanda en DynamoDB, por unidad de petición de lectura | 0 | 44.000 | 440.000 | 4.400.000 |
| ECR | Almacenamiento en repositorios privados de ECR, por GB-mes | 2,4 | 2,4 | 2,4 | 2,4 |
| KMS | Llave de KMS administrada por el cliente; cada una de sus dos primeras rotaciones automáticas suma US$ 1 al mes, por llave-mes | 3 | 3 | 3 | 3 |
| KMS | Peticiones simétricas a KMS, por petición | 105.120 | 125.120 | 305.120 | 2.105.120 |
| Secrets Manager | Secreto de Secrets Manager, por secreto-mes | 5 | 5 | 5 | 5 |
| Secrets Manager | Peticiones a la API de Secrets Manager, por petición | 1.752 | 5.232 | 36.552 | 349.752 |
| SES | Correo saliente de SES, por destinatario, por correo | 0 | 10.000 | 100.000 | 1.000.000 |
| SES | Adjuntos de SES, por GB | 0 | 0,46 | 4,59 | 45,92 |
| Métricas y alarmas de CloudWatch | Métricas personalizadas de CloudWatch, primeras 10.000, por métrica-mes | 20 | 20 | 20 | 20 |
| Métricas y alarmas de CloudWatch | Alarmas de CloudWatch de resolución estándar, por métrica en cada alarma, por métrica de alarma-mes | 31 | 31 | 31 | 31 |
| Métricas y alarmas de CloudWatch | Alarma compuesta de CloudWatch, por alarma-mes | 1 | 1 | 1 | 1 |
| Métricas y alarmas de CloudWatch | Tablero de CloudWatch, por tablero-mes | 1 | 1 | 1 | 1 |
| CloudWatch Logs | Ingesta de CloudWatch Logs, clase Standard (registros de las funciones, y vended logs hasta 10 TB), por GB | 0,25 | 0,53 | 3,05 | 28,32 |
| CloudWatch Logs | Almacenamiento de CloudWatch Logs, por GB-mes | 2,95 | 6,32 | 36,64 | 339,89 |

## Palancas

Lo que cada cambio en la etapa de producción sumaría (+) o ahorraría (−) al mes, frente a la etapa tal como está, sin la capa gratuita.

| Palanca | Qué cambia | Sin facturas | 10.000 facturas | 100.000 facturas | 1.000.000 facturas |
| --- | --- | ---: | ---: | ---: | ---: |
| Quitar la instancia de lectura | Desaparece la instancia de lectura de la segunda zona: un failover espera entonces una instancia nueva en vez de promover la de lectura ([infrastructure.md](infrastructure.md#cuando-algo-falla)). | −US$ 43,80 | −US$ 43,80 | −US$ 65,70 | −US$ 131,40 |
| Activar RDS Proxy | RDS Proxy delante de la base de datos, cobrado por ACU de las instancias de la base de datos, con un mínimo de 8 ACU. | +US$ 87,60 | +US$ 87,60 | +US$ 87,60 | +US$ 87,60 |
| Activar los endpoints de interfaz | Los 5 servicios de AWS sin endpoint de gateway reciben un endpoint de interfaz en cada una de las 2 zonas; su tráfico deja de pasar por el NAT gateway. | +US$ 73,00 | +US$ 72,97 | +US$ 72,67 | +US$ 69,71 |
| Agregar un segundo NAT gateway | Un NAT gateway, con su dirección, en cada zona, para que, si se pierde una zona, todavía quede una salida. | +US$ 36,50 | +US$ 36,50 | +US$ 36,50 | +US$ 36,50 |
| CloudFront PriceClass_100 | Ubicaciones de borde solo en Norteamérica, Europa e Israel: a los visitantes en Colombia se les atiende desde Norteamérica, a sus precios y con más latencia. | US$ 0,00 | −US$ 0,09 | −US$ 0,92 | −US$ 9,20 |
| Conservar los registros 30 días | Cada grupo de registros conserva sus registros 30 días en vez de 365, así que el almacenamiento guarda un mes de registros en vez de 12. | −US$ 0,08 | −US$ 0,17 | −US$ 1,01 | −US$ 9,35 |
| Quitar las métricas y las alarmas | Sin métricas personalizadas, alarmas, alarmas compuestas ni tablero; los registros se quedan, y nada le avisa a nadie. | −US$ 12,60 | −US$ 12,60 | −US$ 12,60 | −US$ 12,60 |
| Pasar las funciones a arm64 | Todas las funciones en Graviton, con un precio 20 % menor por GB-segundo; la imagen de las funciones tiene que construirse para arm64. | −US$ 0,05 | −US$ 0,25 | −US$ 2,07 | −US$ 20,30 |

## Supuestos

Cada valor que usa la estimación, de [`scripts/cost/usage.json`](../../scripts/cost/usage.json), por factura salvo que su unidad diga otra cosa. Las mediciones se tomaron el 23 de noviembre de 2026. El emulador (Floci) corre la etapa local en una sola máquina, así que sus tiempos no son los de AWS: las duraciones de abajo son supuestos, y las cifras del emulador son solo su piso.

La capacidad promedio de cada instancia de la base de datos también es un supuesto, porque ninguna prueba de carga la ha medido: 0,5 ACU sin facturas, 0,5 ACU con 10.000 facturas, 0,75 ACU con 100.000 facturas, 1,5 ACU con 1.000.000 facturas.

### Medidos

Al correr uno de los comandos de este repositorio, en el emulador (make cloud-check) o con los datos de ejemplo (make demo).

| Supuesto | Valor | Unidad | Fuente |
| --- | ---: | --- | --- |
| `dbWebhookEventBytes` | 21.813 | bytes por evento de webhook | Longitud promedio de fila de webhook_events en la base de datos de la demo; se conservan 30 días (backend/config/retention.php). |
| `dbOutboxEventBytes` | 424 | bytes por evento del outbox | Longitud promedio de fila de outbox_events en la base de datos de la demo; se conservan 7 días. |
| `dbTransmissionBytes` | 1.153 | bytes por llamada a la DIAN | Longitud promedio de fila de dian_transmissions en la base de datos de la demo; se conservan 180 días. |
| `dbReadIos` | 0 | E/S de lectura | make cloud-check: Innodb_buffer_pool_reads no creció, así que el conjunto de trabajo se quedó en memoria. |
| `outboxEvents` | 12 | eventos | make cloud-check: 360 eventos del outbox para 30 facturas (paso 6), 493 para 41 (paso 9). |
| `chainMessages` | 7 | mensajes | make cloud-check: 288 eventos enrutados a la cola de la cadena para 41 facturas; 336 trabajos de la cadena para 47 documentos en los registros. |
| `webhookEvents` | 3 | eventos de webhook | make cloud-check: 123 eventos de webhook para 41 facturas (emitida, aceptada, entregada). |
| `objects` | 5 | objetos | make cloud-check: 205 objetos en el bucket para 41 facturas (XML firmado, respuesta de la DIAN, AttachedDocument, PDF y zip). |
| `emails` | 1 | correos | make cloud-check: 41 correos por SES para 41 facturas. |
| `documentBytes` | 144.318 | bytes | Las 60 facturas de la demo en backend/storage/app/documents, en promedio: XML firmado 18.371, respuesta 4.649, AttachedDocument 31.109, PDF 44.268 y zip 45.921 bytes. Cada objeto pesa menos de 128 KB, así que las reglas de ciclo de vida lo dejan en S3 Standard (por defecto, los objetos así de pequeños no cambian de clase de almacenamiento). |
| `pdfBytes` | 44.268 | bytes | El PDF promedio de las facturas de la demo. |
| `zipBytes` | 45.921 | bytes | El zip promedio de las facturas de la demo, el adjunto del correo. |
| `invoiceJsonBytes` | 24.405 | bytes | GET /api/v1/invoices/{id} de una factura aceptada de la demo. |
| `webhookBodyBytes` | 26.951 | bytes por suscripción | Los últimos 200 webhooks del receptor local de webhooks: invoice.issued 7.018, invoice.accepted 9.060 e invoice.delivered 10.873 bytes en promedio. |
| `logBytes` | 14.639 | bytes | make cloud-check: el recorrido de una factura en el CloudWatch Logs del emulador, 33 líneas en JSON (api 1.403, chain 8.082, webhooks 5.154 bytes). |
| `relayLogBytesPerRun` | 1.037 | bytes por ejecución | make cloud-check: 3.111 bytes en 3 ejecuciones del relay. |
| `schedulerLogBytesPerRun` | 1.195 | bytes por ejecución | make cloud-check: 3.586 bytes en 3 ejecuciones del programador. |

### Derivados

Calculados a partir de valores medidos o del código.

| Supuesto | Valor | Unidad | Fuente |
| --- | ---: | --- | --- |
| `dbPermanentBytes` | 20.000 | bytes | La base de datos de la demo (make demo): la longitud promedio de fila multiplicada por las filas de cada documento, en documentos, líneas, impuestos, retenciones, línea de tiempo, archivos, intentos de entrega y pagos: 11,2 KB, más otro tanto para sus índices. Se conservan para siempre. |
| `dbWriteIos` | 88 | E/S de escritura | make cloud-check: los contadores de MySQL Com_insert, Com_update y Com_delete crecieron en 4.121 con 47 documentos, contando cada sentencia como una E/S de escritura de Aurora. Es una cota superior: la revisión también rota identidades y borra sus empresas. |
| `originVerifierCalls` | 8.760 | llamadas al mes | API Gateway guarda en caché la respuesta del autorizador durante 300 s (la plantilla): una llamada cada 5 minutos. |
| `secretReadsPerColdStart` | 2 | peticiones | El secreto de la aplicación y el de la identidad de base de datos propia de la función, que se leen cuando la función arranca (LoadSecrets). |
| `sesBytes` | 70.000 | bytes | El correo con su zip en base64, enviado a la API de SES a través del NAT gateway. |
| `kmsRequests` | 2 | peticiones | Dos firmas, y cada una descifra la llave de datos del certificado (backend/app/Support/Keys/KmsKeyStore.php). |

### De las plantillas

Las plantillas sintetizadas de la etapa de producción.

| Supuesto | Valor | Unidad | Fuente |
| --- | ---: | --- | --- |
| `auroraInstances` | 2 | instancias | Una instancia de escritura y una instancia de lectura con nivel de promoción 1 que escala con la de escritura, así que las dos corren con la misma capacidad (infra/cdk/lib/stage.ts, reader: true; lib/database-stack.ts, scaleWithWriter). |
| `auroraMinAcu` | 0,5 | ACU | El minCapacity de la etapa de producción (infra/cdk/lib/stage.ts); maxCapacity es 4. |
| `natGateways` | 1 | NAT gateways | El natGateways de la etapa de producción, en la primera zona, con una Elastic IP. |
| `zones` | 2 | zonas | El availabilityZones de la etapa de producción. |
| `interfaceEndpointServices` | 5 | servicios | email, kms, secretsmanager, sns y sqs: backend/resources/aws/services.json sin s3 ni dynamodb, que tienen endpoints de gateway. Están desactivados en la etapa de producción; una de las palancas los activa. |
| `kmsKeys` | 3 | llaves | Las llaves de los certificados de firma, de la base de datos y de los temas de las alarmas, con rotación automática y a su precio del primer año. |
| `secrets` | 5 | secretos | El de la aplicación, el del borde, el del webhook del chat y los de las dos identidades de la base de datos. |
| `wafRules` | 5 | reglas | Tres grupos de reglas administrados por AWS y dos reglas basadas en frecuencia en una web ACL. |
| `alarmMetrics` | 31 | métricas de alarma | 16 alarmas; las tres alarmas con métricas calculadas cuentan 8, 8 y 2 métricas. |
| `compositeAlarms` | 1 | alarmas | La alarma service-degraded. |
| `dashboards` | 1 | tableros | El tablero de operación. |
| `customMetrics` | 20 | métricas | 14 nombres de métricas de los filtros de métricas; ChainStepDuration tiene una métrica por cada uno de los 7 trabajos de la cadena. |
| `logRetentionMonths` | 12 | meses | Cada grupo de registros de la etapa de producción conserva sus registros 365 días. |
| `ecrImages` | 20 | imágenes | Las imágenes que conserva el repositorio. |
| `memoryApi` | 1 | GB | El memorySize de la función api (infra/functions/serverless.yml); chain también tiene 1 GB. |
| `memoryChain` | 1 | GB | El memorySize de la función chain. |
| `memoryWebhooks` | 0,5 | GB | El memorySize de la función webhooks. |
| `memoryRelay` | 0,5 | GB | El memorySize de las funciones relay y scheduler. |
| `memoryOriginVerifier` | 0,125 | GB | El memorySize del verificador de origen. |
| `schedulesPerMinute` | 2 | invocaciones por minuto | El relay y el programador, cada uno con rate(1 minute). |
| `topicPublishers` | 6 | roles | Las seis funciones de la aplicación, cada una con su propio rol, que publican en el tema de eventos de documentos. |

### Documentados

La documentación de AWS.

| Supuesto | Valor | Unidad | Fuente |
| --- | ---: | --- | --- |
| `hoursPerMonth` | 730 | horas al mes | El mes que usan los ejemplos de precios de AWS. |

### Asumidos

Una decisión de esta estimación, no una medición: un despliegue real la reemplazaría.

| Supuesto | Valor | Unidad | Fuente |
| --- | ---: | --- | --- |
| `auroraBaseGb` | 1 | GB | El esquema, los datos de referencia y sus índices antes de la primera factura. |
| `imageGb` | 0,12 | GB por imagen | La imagen de las funciones, comprimida en ECR, contada completa por cada imagen conservada (sin capas compartidas): la imagen local pesa 323 MB sin comprimir. |
| `platformLogBytes` | 600 | bytes por invocación | Las líneas de sistema en JSON de Lambda en el nivel INFO (start y report); el emulador no escribe ninguna. |
| `chainJobMs` | 400 | ms por trabajo | Unas diez veces la mediana del emulador, 35,5 ms: una función de 1 GB recibe una parte de una vCPU (una vCPU con 1.769 MB). |
| `dianWaitMs` | 1.500 | ms | La espera de la transmisión hasta la respuesta de la DIAN; el simulador local responde en una mediana de 69 ms, y nunca se llamó al servicio real. |
| `webhookJobMs` | 300 | ms por trabajo | La mediana del emulador es 19,2 ms; una entrega también espera al endpoint del cliente. |
| `apiRequestMs` | 150 | ms por petición | La mediana del emulador es 8,4 ms. |
| `relayRunMs` | 300 | ms por ejecución | Las medianas del emulador son 76 ms para el relay y 88 ms para el programador. |
| `originVerifierMs` | 20 | ms por llamada | Una comparación de dos cadenas y, en frío, una lectura de un secreto. |
| `coldStartShare` | 0,01 | arranques en frío por invocación | Una de cada cien invocaciones arranca un entorno de ejecución nuevo. |
| `initMs` | 1.500 | ms por arranque en frío | El arranque en frío de la API en el emulador es de 0,86 s a través del borde; AWS cobra la inicialización de cada función. |
| `apiRequests` | 4 | peticiones | Una integración crea el borrador, lo emite, lo vuelve a leer y descarga el PDF. |
| `webSessions` | 0,02 | sesiones | Una sesión de la aplicación web por cada 50 facturas. |
| `webRequestsPerSession` | 40 | peticiones | La mitad, archivos de la aplicación web; la otra mitad, llamadas a la API. |
| `webApiRequestsPerSession` | 20 | peticiones | Las llamadas de una sesión a la API. |
| `webBytesPerSession` | 1.000.000 | bytes | Lo que recibe una sesión: los archivos de la compilación de producción y las respuestas de la API. |
| `webhookSubscriptions` | 1 | suscripciones | Un endpoint de webhooks por empresa, suscrito a todos los eventos. |
| `southAmericaShare` | 1 | fracción de las peticiones | Todos los visitantes están en Colombia y llegan a una ubicación de borde de Suramérica, incluida en PriceClass_All. |
| `dianBytes` | 20.000 | bytes | La petición SOAP con el XML firmado comprimido en zip y en base64, y su respuesta, a través del NAT gateway. |
| `snsBytesPerEvent` | 2.000 | bytes | Una publicación en SNS a través del NAT gateway. |
| `sqsRequestsPerMessage` | 3 | peticiones | SNS envía el mensaje, y Lambda lo recibe y lo borra, un mensaje por lote con este tráfico. |
| `sqsIdleRequests` | 1.314.000 | peticiones al mes | Lambda consulta cada cola con sondeo largo mientras está vacía; se cuentan como cinco procesos de sondeo en cada una de las dos colas, cada uno con una recepción cada 20 s. |
| `s3Gets` | 8 | peticiones | La cadena vuelve a leer sus archivos para firmarlos, enviarlos, armar el AttachedDocument y mandarlos por correo, y la integración descarga dos. |
| `dynamoWritesPerRequest` | 1 | unidades de escritura por petición a la API | El contador del límite de peticiones en la tabla limits. |
| `dynamoReadsPerRequest` | 1 | unidades de lectura por petición a la API | El contador del límite de peticiones, leído de nuevo. |
| `dynamoSchedulerWritesPerMinute` | 4 | unidades de escritura por minuto | Los bloqueos y el latido del programador en la tabla cache. |
| `flowLogBytes` | 3.000 | bytes | Los registros de flujo de la VPC que dejan las conexiones de una factura. |
| `flowLogBytesIdle` | 30.000.000 | bytes al mes | Los registros de flujo de las ejecuciones del relay y del programador. |
| `databaseLogBytesIdle` | 50.000.000 | bytes al mes | Los registros de errores, de consultas lentas y de auditoría (conexiones y cambios de usuarios y de esquema) exportados a CloudWatch Logs. |
| `firewallLogBytesIdle` | 10.000.000 | bytes al mes | AWS WAF solo registra las peticiones que bloquea o cuenta. |
| `edgeLogBytesPerRequest` | 500 | bytes | Una línea de los registros estándar de CloudFront en S3, que se conservan 365 días. |

## Precios

Precios bajo demanda para us-east-1, en dólares estadounidenses, consultados el 22 de noviembre de 2026 en las páginas oficiales de precios y en la AWS Price List API (`https://pricing.us-east-1.amazonaws.com`), cuyo archivo, versión y tipo de uso nombra cada fila. El primer tramo de cada precio se aplica en todos los escenarios: el script falla si una cantidad lo supera. Todos los precios se confirmaron.

| Precio | Qué | USD | Unidad | Siempre gratis al mes | Fuente |
| --- | --- | ---: | --- | ---: | --- |
| `lambdaRequest` | Peticiones a Lambda | US$ 0,0000002 | petición | 1.000.000 | [Página de precios](https://aws.amazon.com/lambda/pricing/); AWSLambda 20261120203647, Request |
| `lambdaGbSecondX86` | Duración de Lambda en x86_64, primeros 6 mil millones de GB-segundo | US$ 0,0000166667 | GB-segundo | 400.000 | [Página de precios](https://aws.amazon.com/lambda/pricing/); AWSLambda 20261120203647, Lambda-GB-Second |
| `lambdaGbSecondArm` | Duración de Lambda en arm64, primeros 7,5 mil millones de GB-segundo | US$ 0,0000133334 | GB-segundo | 400.000 | [Página de precios](https://aws.amazon.com/lambda/pricing/); AWSLambda 20261120203647, Lambda-GB-Second-ARM |
| `httpApiRequest` | Peticiones a las API HTTP de API Gateway, primeros 300 millones | US$ 0,000001 | petición |  | [Página de precios](https://aws.amazon.com/api-gateway/pricing/); AmazonApiGateway 20261112215920, USE1-ApiGatewayHttpRequest |
| `cloudfrontRequestSouthAmerica` | Peticiones HTTPS a CloudFront atendidas en Suramérica | US$ 0,0000022 | petición HTTPS | 10.000.000 | [Página de precios](https://aws.amazon.com/cloudfront/pricing/); AmazonCloudFront 20261019150500, SA-Requests-Tier2-HTTPS |
| `cloudfrontRequestUnitedStates` | Peticiones HTTPS a CloudFront atendidas en Estados Unidos | US$ 0,000001 | petición HTTPS | 10.000.000 | [Página de precios](https://aws.amazon.com/cloudfront/pricing/); AmazonCloudFront 20261019150500, US-Requests-Tier2-HTTPS |
| `cloudfrontTransferSouthAmerica` | Transferencia de datos de salida de CloudFront en Suramérica, primeros 10 TB | US$ 0,11 | GB | 1.024 | [Página de precios](https://aws.amazon.com/cloudfront/pricing/); AmazonCloudFront 20261019150500, SA-DataTransfer-Out-Bytes |
| `cloudfrontTransferUnitedStates` | Transferencia de datos de salida de CloudFront en Estados Unidos, primeros 10 TB | US$ 0,085 | GB | 1.024 | [Página de precios](https://aws.amazon.com/cloudfront/pricing/); AmazonCloudFront 20261019150500, US-DataTransfer-Out-Bytes |
| `cloudfrontFunction` | Invocaciones de CloudFront Functions | US$ 0,0000001 | invocación | 2.000.000 | [Página de precios](https://aws.amazon.com/cloudfront/pricing/); AmazonCloudFront 20261019150500, Executions-CloudFrontFunctions |
| `wafWebAcl` | Web ACL de AWS WAF | US$ 5,00 | web ACL-mes |  | [Página de precios](https://aws.amazon.com/waf/pricing/); awswaf 20260914163921, USE1-WebACLV2 |
| `wafRule` | Regla de AWS WAF, o grupo de reglas agregado a una web ACL | US$ 1,00 | regla-mes |  | [Página de precios](https://aws.amazon.com/waf/pricing/); awswaf 20260914163921, USE1-RuleV2 |
| `wafRequest` | Peticiones de AWS WAF, hasta 1.500 WCU | US$ 0,0000006 | petición |  | [Página de precios](https://aws.amazon.com/waf/pricing/); awswaf 20260914163921, USE1-RequestV2-Tier1 |
| `snsRequest` | Peticiones de publicación en SNS, después del primer millón | US$ 0,0000005 | petición | 1.000.000 | [Página de precios](https://aws.amazon.com/sns/pricing/); AmazonSNS 20261122222801, Requests-Tier1 |
| `sqsRequest` | Peticiones a colas estándar de SQS, primeros 100 mil millones | US$ 0,0000004 | petición | 1.000.000 | [Página de precios](https://aws.amazon.com/sqs/pricing/); AWSQueueService 20261111153551, Requests-RBP |
| `s3Standard` | Almacenamiento en S3 Standard, primeros 50 TB | US$ 0,023 | GB-mes |  | [Página de precios](https://aws.amazon.com/s3/pricing/); AmazonS3 20261121172919, TimedStorage-ByteHrs |
| `s3Put` | Peticiones PUT, COPY, POST y LIST a S3 | US$ 0,000005 | petición |  | [Página de precios](https://aws.amazon.com/s3/pricing/); AmazonS3 20261121172919, Requests-Tier1 |
| `s3Get` | Peticiones GET y demás peticiones a S3 | US$ 0,0000004 | petición |  | [Página de precios](https://aws.amazon.com/s3/pricing/); AmazonS3 20261121172919, Requests-Tier2 |
| `dynamodbWrite` | Escrituras bajo demanda en DynamoDB | US$ 0,000000625 | unidad de petición de escritura |  | [Página de precios](https://aws.amazon.com/dynamodb/pricing/on-demand/); AmazonDynamoDB 20261111153612, WriteRequestUnits |
| `dynamodbRead` | Lecturas bajo demanda en DynamoDB | US$ 0,000000125 | unidad de petición de lectura |  | [Página de precios](https://aws.amazon.com/dynamodb/pricing/on-demand/); AmazonDynamoDB 20261111153612, ReadRequestUnits |
| `dynamodbStorage` | Almacenamiento en DynamoDB, después de los primeros 25 GB | US$ 0,25 | GB-mes | 25 | [Página de precios](https://aws.amazon.com/dynamodb/pricing/on-demand/); AmazonDynamoDB 20261111153612, TimedStorage-ByteHrs |
| `kmsKey` | Llave de KMS administrada por el cliente; cada una de sus dos primeras rotaciones automáticas suma US$ 1 al mes | US$ 1,00 | llave-mes |  | [Página de precios](https://aws.amazon.com/kms/pricing/); awskms 20261108172342, us-east-1-KMS-Keys |
| `kmsRequest` | Peticiones simétricas a KMS | US$ 0,000003 | petición | 20.000 | [Página de precios](https://aws.amazon.com/kms/pricing/); awskms 20261108172342, us-east-1-KMS-Requests |
| `secret` | Secreto de Secrets Manager | US$ 0,40 | secreto-mes |  | [Página de precios](https://aws.amazon.com/secrets-manager/pricing/); AWSSecretsManager 20261111195606, USE1-AWSSecretsManager-Secrets |
| `secretRequest` | Peticiones a la API de Secrets Manager | US$ 0,000005 | petición |  | [Página de precios](https://aws.amazon.com/secrets-manager/pricing/); AWSSecretsManager 20261111195606, USE1-AWSSecretsManagerAPIRequest |
| `sesEmail` | Correo saliente de SES, por destinatario | US$ 0,0001 | correo |  | [Página de precios](https://aws.amazon.com/ses/pricing/); AmazonSES 20261104174109, USE1-Recipients |
| `sesAttachment` | Adjuntos de SES | US$ 0,12 | GB |  | [Página de precios](https://aws.amazon.com/ses/pricing/); AmazonSES 20261104174109, AttachmentsSize-Bytes |
| `schedulerInvocation` | Invocaciones de EventBridge Scheduler, después de los primeros 14 millones | US$ 0,000001 | invocación | 14.000.000 | [Página de precios](https://aws.amazon.com/eventbridge/pricing/); AWSEvents 20261118214401, USE1-ScheduledInvocation |
| `cloudwatchMetric` | Métricas personalizadas de CloudWatch, primeras 10.000 | US$ 0,30 | métrica-mes | 10 | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); AmazonCloudWatch 20261119171040, CW:MetricMonitorUsage |
| `cloudwatchAlarm` | Alarmas de CloudWatch de resolución estándar, por métrica en cada alarma | US$ 0,10 | métrica de alarma-mes | 10 | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); AmazonCloudWatch 20261119171040, CW:AlarmMonitorUsage |
| `cloudwatchCompositeAlarm` | Alarma compuesta de CloudWatch | US$ 0,50 | alarma-mes |  | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); AmazonCloudWatch 20261119171040, CW:CompositeAlarmMonitorUsage |
| `cloudwatchDashboard` | Tablero de CloudWatch | US$ 3,00 | tablero-mes | 3 | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); No está en la AWS Price List API; el archivo de datos de la página de precios https://b0.p.awsstatic.com/pricing/2.0/meteredUnitMaps/cloudwatch/USD/current/cloudwatch.json, `Dashboard Basic Dashboard` y `Dashboard Basic Free upto 3 Dashboard` |
| `logsIngestion` | Ingesta de CloudWatch Logs, clase Standard (registros de las funciones, y vended logs hasta 10 TB) | US$ 0,50 | GB | 5 | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); AmazonCloudWatch 20261119171040, USE1-DataProcessing-Bytes y USE1-VendedLog-Bytes |
| `logsStorage` | Almacenamiento de CloudWatch Logs | US$ 0,03 | GB-mes | 5 | [Página de precios](https://aws.amazon.com/cloudwatch/pricing/); AmazonCloudWatch 20261119171040, USE1-TimedStorage-ByteHrs |
| `auroraAcuHour` | Aurora MySQL Serverless v2, Aurora Standard | US$ 0,12 | ACU-hora |  | [Página de precios](https://aws.amazon.com/rds/aurora/pricing/); AmazonRDS 20261120224619, Aurora:ServerlessV2Usage |
| `auroraStorage` | Almacenamiento de Aurora, Aurora Standard | US$ 0,10 | GB-mes |  | [Página de precios](https://aws.amazon.com/rds/aurora/pricing/); AmazonRDS 20261120224619, Aurora:StorageUsage |
| `auroraIo` | Peticiones de E/S de Aurora, Aurora Standard | US$ 0,0000002 | petición de E/S |  | [Página de precios](https://aws.amazon.com/rds/aurora/pricing/); AmazonRDS 20261120224619, Aurora:StorageIOUsage |
| `auroraBackup` | Almacenamiento de copias de seguridad de Aurora MySQL más allá del 100 % del almacenamiento del clúster | US$ 0,021 | GB-mes |  | [Página de precios](https://aws.amazon.com/rds/aurora/pricing/); AmazonRDS 20261120224619, Aurora:BackupUsage |
| `rdsProxyAcuHour` | RDS Proxy para Aurora Serverless v2, con un cobro mínimo de 8 ACU | US$ 0,015 | ACU-hora |  | [Página de precios](https://aws.amazon.com/rds/proxy/pricing/); AmazonRDS 20261120224619, USE1-RDS:Proxy-ASv2-Usage; el mínimo de 8 ACU sale de la tabla de la página de precios |
| `natHour` | NAT gateway | US$ 0,045 | hora |  | [Página de precios](https://aws.amazon.com/vpc/pricing/); AmazonEC2 20261121004816, NatGateway-Hours |
| `natGb` | Datos procesados por un NAT gateway | US$ 0,045 | GB |  | [Página de precios](https://aws.amazon.com/vpc/pricing/); AmazonEC2 20261121004816, NatGateway-Bytes |
| `publicIpv4Hour` | Dirección IPv4 pública en uso (la Elastic IP del NAT gateway) | US$ 0,005 | hora |  | [Página de precios](https://aws.amazon.com/vpc/pricing/); AmazonVPC 20261119205545, USE1-PublicIPv4:InUseAddress |
| `endpointHour` | Endpoint de interfaz de VPC | US$ 0,01 | endpoint-hora, por zona |  | [Página de precios](https://aws.amazon.com/privatelink/pricing/); AmazonVPC 20261119205545, USE1-VpcEndpoint-Hours |
| `endpointGb` | Datos procesados por los endpoints de interfaz, primer PB | US$ 0,01 | GB |  | [Página de precios](https://aws.amazon.com/privatelink/pricing/); AmazonVPC 20261119205545, USE1-VpcEndpoint-Bytes |
| `internetTransfer` | Transferencia de datos de salida a internet desde us-east-1, primeros 10 TB | US$ 0,09 | GB | 100 | [Página de precios](https://aws.amazon.com/ec2/pricing/on-demand/#Data_Transfer); AWSDataTransfer 20261119105841, DataTransfer-Out-Bytes y Global-DataTransfer-Out-Bytes |
| `ecrStorage` | Almacenamiento en repositorios privados de ECR | US$ 0,10 | GB-mes |  | [Página de precios](https://aws.amazon.com/ecr/pricing/); AmazonECR 20261113163947, TimedStorage-ByteHrs |

## Lo que no está en la estimación

- Los impuestos, un plan de soporte, y los costos de la cuenta de AWS, de su organización y de sus servicios de seguridad (CloudTrail más allá del trail gratuito, GuardDuty, Security Hub).
- El dominio y su DNS: aquí no se administra ninguna zona alojada ([deployment.md](deployment.md)). El certificado de ACM es gratis.
- La transferencia de datos entre las dos zonas, dentro de la región: las funciones corren en las dos y la instancia de escritura de la base de datos en una.
- Los reintentos, las fallas y los simulacros: los escenarios cuentan el camino feliz de cada factura, como lo mide make cloud-check.
- Los despliegues: la integración continua, la construcción de las imágenes y las subidas de la aplicación web.
- Los Savings Plans, la capacidad reservada, y las ofertas de 12 meses y los créditos de una cuenta nueva.
