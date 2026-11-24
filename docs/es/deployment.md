# Despliegue

[English](../deployment.md) · **Español**

<!-- Traducción de docs/deployment.md con SHA-256 d16e14fa355338ac29bdcdb487d9a3afa17ec994297d75d3255b7d215864badc. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Esta guía explica cómo desplegar la plataforma en una cuenta real de AWS. El despliegue está escrito como código y se valida sin conexión, pero nunca ha corrido: nada de este repositorio se ha desplegado en una cuenta (docs/es/limitations.md).

La nube emulada corre el mismo guion, con los mismos pasos, contra el emulador local (`make cloud-up`). `make cdk-check` sintetiza, empaqueta y revisa la etapa de producción.

Las secciones de abajo cubren lo que necesita una cuenta, el orden de un primer despliegue, un despliegue de rutina, cómo volver atrás, cómo desmontarlo y lo que nunca se ha verificado.

## Un guion para todas las etapas

`scripts/deploy.sh <local|prod> <step>` despliega una etapa. `make cloud-up` corre sus pasos contra el emulador, y el flujo de despliegue `.github/workflows/deploy.yml` corre esos mismos pasos contra una cuenta.

Cada herramienta corre en un contenedor de `compose.yaml`:

- `cdk-deploy` y `functions-deploy` se conectan al emulador.
- `cdk-release` y `functions-release` (perfil `release`) se conectan a una cuenta, con las credenciales del rol que asumió el flujo de despliegue.

| Paso | `make cloud-up` (etapa local) | El flujo de despliegue (etapa de producción) |
| --- | --- | --- |
| `stacks` | SOLO EN EL EMULADOR: `cdk bootstrap` en cada ejecución, porque el emulador puede ser nuevo. Luego, `cdk deploy` de messaging, storage, data, security, mail, alerts y compute. | `cdk deploy` de los mismos stacks, más network y database. El bootstrap de la cuenta se hizo una vez, a mano. |
| `image` | Construye el target `lambda` de `backend/Dockerfile`. SOLO EN EL EMULADOR: sube la imagen al registro de imágenes que hay detrás del ECR del emulador, por su puerto publicado. | Construye la imagen igual y luego la sube al repositorio de ECR de la cuenta, con la etiqueta de su commit. Esa etiqueta nunca se mueve: un commit que ya se había subido reutiliza su imagen. |
| `migrate` | SOLO EN EL EMULADOR: crea la base de datos de la nube emulada y su identidad de migraciones en el MySQL de Docker Compose (en AWS, Aurora crea su usuario maestro a partir del secreto). Luego, lo mismo que en producción. | `osls deploy` pone la imagen nueva solo en la función de consola (`consoleImage`), y las demás funciones se quedan en la imagen que ya corren. Luego la función de consola corre `php artisan migrate` y `php artisan db:application-identity`. |
| `functions` | `osls deploy` pone cada función en la imagen nueva, por su resumen, con el token de administración del simulador de la DIAN como parámetro (una herramienta de desarrollo). | Lo mismo, con los parámetros de la cuenta: su id, el dominio (`appUrl`, `statefulDomains`) y la concurrencia reservada. |
| `edge` | `cdk deploy` del stack edge, con el valor del secreto del borde pasado como parámetro por una tubería. SOLO EN EL EMULADOR: luego `edge.mjs reconcile` aplica los ajustes de los comportamientos que descarta el CloudFormation del emulador. | `cdk deploy` del stack edge. CloudFormation resuelve el secreto del borde desde Secrets Manager, y `ORIGIN_VERIFY_VERSION` puede fijar una versión. |
| `observability` | `cdk deploy` del stack observability, por separado. SOLO EN EL EMULADOR: luego `observability.mjs reconcile` agrega las métricas de las alarmas con métricas calculadas y las consultas guardadas. | `cdk deploy` del stack observability, por separado. |
| `panel` | `pnpm build` y luego `panel.mjs upload`. El emulador no guarda nada en caché, así que no hay invalidación. | Lo mismo, más una invalidación de los archivos que no llevan huella. |
| `gateway` | SOLO EN EL EMULADOR: el proxy delante de la distribución, que el emulador sirve por nombre de host. | Ninguno: el dominio apunta a la distribución. |
| `smoke` | Revisa la salud de la API a través del borde (el proxy), y que API Gateway rechace una llamada sin secreto y otra con un secreto inventado. | Lo mismo, a través del dominio y de la dirección pública de API Gateway. |
| `versions`, `rollback-image`, `rollback-panel`, `diff` | `make cloud-deploy STEP=<step>` | A mano: `sh scripts/deploy.sh prod <step>`, con las credenciales de un operador. `diff` y `versions` también son pasos del flujo de despliegue. |

En las dos etapas, el paso `image` imprime la imagen como `<registry>/dian-einvoice-<stage>-functions@sha256:<digest>`, y cada función se despliega por ese resumen.

Los pasos que difieren existen solo porque el emulador no es AWS. El guion marca cada uno con `EMULATOR ONLY` y dice qué hace AWS en su lugar.

## Lo que necesita una cuenta

### La concurrencia de Lambda

Las funciones reservan 72 ejecuciones simultáneas en total: api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, el verificador de origen 10 y el notificador 2. Las funciones de la aplicación tienen una conexión a la base de datos cada una: 60 como máximo, dentro de las 135 conexiones que acepta la base de datos con 4 ACU.

Lambda deja que una cuenta reserve como máximo su concurrencia no reservada menos 100, y guarda esos 100 para las funciones que no reservan nada. Así que la cuota de ejecuciones simultáneas de la cuenta debe ser de al menos 72 + 100 = **172**. La cuota por defecto es de 1.000 por región, pero las cuentas nuevas empiezan con cuotas reducidas, que AWS sube con el uso. Reservar concurrencia no cuesta nada.

1. Lee la cuota con `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384 --query Quota.Value`, y la concurrencia no reservada con `aws lambda get-account-settings --query AccountLimit`.
2. Si la cuota está por debajo de 172, pide más: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`.
3. Mientras AWS no la suba, despliega sin concurrencia reservada: pon la entrada `reserved-concurrency` del flujo de despliegue en `unreserved`. Se convierte en el `--param=reservedConcurrency=unreserved` del servicio serverless, que deja `ReservedConcurrentExecutions` por fuera de todas las funciones.

   Los mapeos de las colas siguen limitando la cadena a 10 y los webhooks a 5, y la cuota de la cuenta limita todas las funciones juntas. Con concurrencia reservada, las funciones de la aplicación tienen como máximo 60 conexiones, una por entorno de ejecución, holgadamente dentro de las 135 de la base de datos y de su margen de 20 para los operadores y las funciones de rotación. Sin ella, una cuota por debajo de unas 115 (135 menos ese margen) mantiene a las funciones dentro de esas conexiones.

   Despliega de nuevo con `reserved` cuando la cuota lo permita. La concurrencia reservada es lo que impide que una ráfaga en la API se lleve las conexiones de la cadena.

### Correo: SES fuera de su sandbox, y los registros DNS del dominio

En una cuenta nueva, SES corre en su sandbox: solo envía a direcciones verificadas, y solo 200 correos al día. El stack mail verifica el dominio de la plataforma con Easy DKIM y un subdominio MAIL FROM (`mail.<domain>`).

1. Pide el acceso de producción con `aws sesv2 put-account-details --production-access-enabled --mail-type TRANSACTIONAL --website-url https://<domain> --use-case-description "Electronic invoices and notes, sent to the customers of each company once the DIAN validates them" --contact-language EN`. Compruébalo con `aws sesv2 get-account --query "[ProductionAccessEnabled,SendQuota]"`.
2. Después del primer despliegue, publica en la zona del dominio los registros que pide la identidad:
   - los tres registros CNAME de DKIM, de `<token>._domainkey.<domain>` a `<token>.dkim.amazonses.com`, uno por cada token que devuelve `aws sesv2 get-email-identity --email-identity <domain> --query DkimAttributes.Tokens`;
   - los registros MX y TXT (SPF) de `mail.<domain>`;
   - un registro DMARC para `_dmarc.<domain>`.

   Este repositorio no administra ninguna zona alojada: quien tenga el dominio publica los registros.

### El dominio, su zona y su certificado

1. Un dominio para la plataforma, `EDGE_DOMAIN_NAME` (por ejemplo `app.<company domain>`), en una zona que administra su dueño.
2. Un certificado de ACM para ese nombre, en us-east-1, como exige CloudFront. Pídelo con `aws acm request-certificate --region us-east-1 --domain-name <domain> --validation-method DNS`, y publica su registro de validación en la zona (`aws acm describe-certificate --region us-east-1 --certificate-arn <arn> --query Certificate.DomainValidationOptions`). Su ARN va en `EDGE_CERTIFICATE_ARN`.
3. Después del primer despliegue, apunta el dominio a la distribución: un alias o un CNAME al nombre que guarda `/dian-einvoice/prod/edge/distribution-domain-name`.

Un despliegue en una cuenta exige tanto el dominio como el certificado. Las funciones toman el dominio como su origen público antes de que exista el borde: lo usan en los enlaces que generan y como origen de las sesiones de la aplicación web.

La app de CDK también puede servir el borde en el nombre que CloudFront le da a la distribución, con su certificado por defecto (`-c edgeDomainName=`, como lo sintetizan las pruebas). `scripts/deploy.sh prod` no usa esa opción.

### El bootstrap de CDK

Un administrador hace el bootstrap de CDK una vez por cuenta y región, con sus propias credenciales en las variables de entorno: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk bootstrap aws://<account>/us-east-1 --cloudformation-execution-policies <policy ARN> -c account=<account>`.

El rol de despliegue asume los roles que crea el bootstrap (calificador `hnb659fds`). La política de ejecución define lo que CloudFormation puede hacer con cada stack de la app de CDK; si no se da ninguna, es AdministratorAccess. Una política limitada a los servicios de la plataforma la acotaría, pero este repositorio no incluye ninguna (docs/es/limitations.md).

### GitHub: el entorno, sus variables y sus revisores

En la configuración del repositorio, crea un entorno llamado `production`:

- **Required reviewers**: los operadores que aprueban cada despliegue. El trabajo espera a uno de ellos antes de asumir el rol.
- **Deployment branches and tags**: `main`, que está protegida, y las etiquetas de versión (`v*.*.*`).
- **Variables** (no secretos: OpenID Connect no necesita ninguna llave):

| Variable | Valor |
| --- | --- |
| `DEPLOY_ROLE_ARN` | el ARN del rol del stack delivery, en `/dian-einvoice/prod/delivery/deploy-role-arn` |
| `AWS_ACCOUNT_ID` | el id de 12 dígitos de la cuenta |
| `EDGE_DOMAIN_NAME` | el dominio de la plataforma |
| `EDGE_CERTIFICATE_ARN` | el ARN del certificado de ese dominio en us-east-1 |
| `ALERTS_EMAIL` | la dirección a la que el tema de respaldo de las alertas envía sus correos, o vacía |

Si `DEPLOY_ROLE_ARN`, `AWS_ACCOUNT_ID`, `EDGE_DOMAIN_NAME` o `EDGE_CERTIFICATE_ARN` está vacía, el flujo de despliegue se detiene en su primer paso con un mensaje que nombra lo que falta.

### El rol de despliegue

Lo crea el stack `dian-einvoice-prod-delivery` (CDK, solo en producción), junto con el proveedor de OpenID Connect de GitHub Actions. Si la cuenta ya tiene ese proveedor, el stack lo reutiliza: `-c githubOidcProviderArn=arn:aws:iam::<account>:oidc-provider/token.actions.githubusercontent.com`.

- **Confianza**: `sts:AssumeRoleWithWebIdentity` solo desde ese proveedor, cuando la audiencia es exactamente `sts.amazonaws.com` y el sujeto es exactamente `repo:<owner>/<repository>:environment:production`. El dueño y el repositorio son parámetros del despliegue (`-c githubOwner=... -c githubRepository=...`). Ninguna otra rama, entorno, fork ni repositorio puede asumir el rol. Las credenciales duran una hora.
- **Permisos**, cada uno solo sobre los recursos de este proyecto:
  - asumir los roles del bootstrap de CDK (`cdk diff`, `cdk deploy`);
  - crear, actualizar y describir el stack de las funciones y su conjunto de cambios, y pasarle solo el rol de despliegue de las funciones;
  - administrar los artefactos de despliegue de la etapa en el bucket de despliegues;
  - subir al repositorio de imágenes, e iniciar sesión en el registro de imágenes (la única acción sin recurso);
  - invocar la función de consola (las migraciones);
  - subir, listar y restaurar la aplicación web en su bucket, e invalidar la distribución etiquetada con `project=dian-einvoice`, `stage=prod`;
  - leer los parámetros, las funciones y los stacks de la etapa (para la prueba de humo y el resumen).

  Ninguna política administrada, ningún acceso de administrador y ninguna acción con comodín. Cada recurso con comodín tiene su razón en `lib/delivery-stack.ts`, y una prueba los revisa (`infra/cdk/test/delivery.test.ts`). El rol no puede cambiar una función, un rol ni un secreto por fuera de CloudFormation.
- **El rol de despliegue de las funciones**: con él, CloudFormation despliega el stack de las funciones (el `provider.iam.deploymentRole` del servicio serverless). Cubre las funciones, su API HTTP, sus grupos de registros, sus programaciones y sus mapeos, y la suscripción del notificador y su parámetro, cada uno por los nombres de la etapa. Pasa los roles de las funciones solo a Lambda, y el rol de las programaciones solo a EventBridge Scheduler.

El pipeline nunca despliega el stack delivery: solo un administrador cambia su confianza y sus permisos, a mano.

### Los secretos que un operador pone a mano

- **La dirección del webhook del chat**, que entrega el servicio de chat: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`. Mientras no esté puesta, el notificador solo registra cada alarma.
- **La confirmación del correo de respaldo**: SNS envía una confirmación a `ALERTS_EMAIL`, y alguien la abre una vez.
- **Generados por Secrets Manager, nunca escritos a mano**: `APP_KEY` (`dian-einvoice/prod/app`), el secreto del borde y las credenciales de las dos identidades de la base de datos.
- **La DIAN**: este proyecto nunca habla con la DIAN. Su dirección en producción es un valor de relleno (el `dianSoapUrl` del servicio serverless), y el WS-Security del servicio web real no está implementado (docs/es/limitations.md). Las direcciones fijas de salida (`/dian-einvoice/prod/network/egress-addresses`) son las que hay que poner en una lista de direcciones permitidas en la DIAN o en un cliente.

## El primer despliegue

1. **La cuenta**: la cuota de Lambda, el acceso de producción de SES y el certificado, como se explicó arriba.
2. **El bootstrap de CDK**, por parte de un administrador.
3. **El stack delivery**, por parte de un administrador: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk deploy prod/delivery -c account=<account> -c githubOwner=<owner> -c githubRepository=<repository>`. El ARN del rol está en `/dian-einvoice/prod/delivery/deploy-role-arn` (`aws ssm get-parameter --name /dian-einvoice/prod/delivery/deploy-role-arn --query Parameter.Value --output text`).
4. **El entorno `production` de GitHub**, con sus revisores y sus variables.
5. **El flujo Deploy**, lanzado desde `main` (Actions, Deploy, Run workflow) y aprobado por un revisor. El flujo:
   - corre las revisiones sin conexión (`make cdk-check`, `make migrations-check`);
   - asume el rol;
   - muestra las diferencias;
   - despliega los stacks que van antes de las funciones. La red y la base de datos son las que más tardan: Aurora crea su clúster, su instancia de lectura y la primera rotación de su usuario maestro;
   - sube la imagen, por su resumen;
   - corre las migraciones;
   - despliega las funciones;
   - despliega el borde (una distribución tarda minutos en desplegarse);
   - despliega el stack observability;
   - sube la aplicación web;
   - corre la prueba de humo y escribe el resumen.
6. **Los registros DNS del dominio**: el alias a la distribución y los registros de SES.
7. **Los secretos que se ponen a mano**: la dirección del webhook del chat y la confirmación del correo de respaldo.
8. **Compruébalo**: `sh scripts/deploy.sh prod smoke` a través del dominio, la creación de una cuenta y una factura en la aplicación web, y una alarma forzada que llega al chat ([docs/es/runbooks/notifier.md](runbooks/notifier.md), paso 7).

## Un despliegue de rutina

1. Haz merge a `main` cuando la integración continua haya pasado en el pull request.
2. Lanza el flujo Deploy desde `main`, o desde una etiqueta de versión (`vMAJOR.MINOR.PATCH`), y apruébalo.
3. El flujo de despliegue corre las revisiones sin conexión, muestra las diferencias y luego despliega.

   Las migraciones corren antes de que las funciones tomen el código nuevo. Así que, durante unos minutos, el código de la versión anterior corre sobre el esquema migrado: una migración puede agregar, pero nunca quitar.

   `make migrations-check` es un paso del flujo de despliegue y una prueba de `make test`. Falla ante una migración que borra o renombra una columna o una tabla, salvo que una anotación nombre la versión desde la que ese cambio es seguro (`// migration-safety: safe since vX.Y.Z: <why>`). Quita algo solo cuando ninguna versión en marcha lo lea (expandir, publicar, contraer).
4. El resumen de la ejecución lista la imagen de cada función por su resumen, los stacks, la versión de la aplicación web y las imágenes recientes.

## Volver atrás

- **La imagen**: devuelve todas las funciones a una imagen anterior, por su resumen, con `sh scripts/deploy.sh prod rollback-image <registry>/dian-einvoice-prod-functions@sha256:<digest>`. El resumen de una ejecución o `sh scripts/deploy.sh prod versions` dan las imágenes anteriores. La base de datos se queda como está.
- **La aplicación web**: `sh scripts/deploy.sh prod rollback-panel` restaura la compilación anterior a la actual, `index.html` incluido, con su invalidación. Los archivos con huella de esa compilación se quedan en el bucket una semana.
- **La infraestructura**: CloudFormation revierte por su cuenta una actualización fallida. Para volver a una versión anterior de un stack, lanza el flujo de despliegue desde la etiqueta de esa versión. El flujo muestra las diferencias y despliega esas plantillas, con la imagen de esa versión. Para un stack que quedó en `UPDATE_ROLLBACK_FAILED`, arregla lo que lo bloqueó y luego corre `aws cloudformation continue-update-rollback --stack-name <stack>`.
- **La base de datos solo va hacia adelante.** En producción no se revierte ninguna migración. El código de la versión anterior corre sobre el esquema migrado (la revisión de arriba), y una migración nueva corrige una equivocada. Si un bug o una equivocación dañan datos, restaura la base de datos a un momento dado, en un clúster nuevo. Luego copia de vuelta las filas desde ese clúster (mira la sección de la base de datos de docs/es/infrastructure.md).

## Desmontarlo

Los stacks de producción tienen protección contra la terminación. Primero quítala en cada uno: `aws cloudformation update-termination-protection --no-enable-termination-protection --stack-name <stack>`. Luego borra los stacks en el orden inverso al del despliegue: observability, edge, el de las funciones (`aws cloudformation delete-stack --stack-name dian-einvoice-prod-functions`), compute, database, network, alerts, mail, security, data, storage, messaging y, al final, delivery.

Algunos recursos sobreviven a su stack a propósito (`RemovalPolicy.RETAIN` en producción, docs/es/infrastructure.md), para que un borrado por error no pierda nada:

- **La base de datos**: el clúster, que además tiene protección contra el borrado, con su llave, sus secretos y sus grupos de registros. Sigue corriendo, y sigue costando. Para apagarlo del todo, toma un snapshot final y bórralo a mano: `aws rds modify-db-cluster --db-cluster-identifier dian-einvoice-prod-database --no-deletion-protection`, y luego `aws rds delete-db-cluster --db-cluster-identifier dian-einvoice-prod-database --final-db-snapshot-identifier <name>`.
- **Los buckets**: el de los documentos (las facturas, guardadas los años que exige la ley), el de sus registros de acceso, el de los despliegues, el de la aplicación web y el de los registros del borde.
- **Las llaves de KMS** de los certificados de firma y de los temas de las alarmas. Cada una tiene un cobro mensual hasta que se programe su borrado.
- **Los secretos**: `APP_KEY` (sin él, no se pueden leer los valores que cifró), el secreto del borde y la dirección del webhook del chat.
- **El repositorio de imágenes**, con sus imágenes.
- **Las direcciones fijas de salida** (Elastic IP), para que una lista de direcciones permitidas que las nombre sobreviva al stack.
- **Los registros de flujo de la VPC** y los registros del firewall.
- Las tablas de DynamoDB (la caché, las sesiones y los límites de peticiones), que CDK conserva por defecto. No guardan nada que deba sobrevivir a la plataforma.

## Lo que nunca se verificó en una cuenta real

Nada de este repositorio ha corrido contra AWS. El emulador corre el guion, las funciones, las colas, el borde y las alarmas sobre una sola métrica. La síntesis, cdk-nag, cfn-lint y actionlint revisan el resto sin conexión. Nunca se verificó:

- El flujo de despliegue en sí: OpenID Connect, la confianza del rol de despliegue con un token real, los revisores del entorno y cada paso con credenciales reales.
- Que los permisos del rol de despliegue y del rol de despliegue de las funciones alcancen, y que no sobren. Están escritos a partir de las llamadas que hacen la CLI de CDK y osls, y nunca se pusieron a prueba.
- La política de ejecución del bootstrap de CDK.
- El push a ECR con etiquetas inmutables, su escaneo al subir, su ciclo de vida y su cifrado con la llave de ECR, y que Lambda descargue de allí una imagen por su resumen.
- El despliegue de la red y de la base de datos (Aurora, su instancia de lectura, las funciones de rotación, los grupos de seguridad y las rutas), el orden del primer despliegue y cuánto tarda.
- El borde con un dominio y su certificado, el firewall, la invalidación de la aplicación web y la prueba de humo a través de un dominio real y de la dirección pública de API Gateway.
- SES fuera de su sandbox, los registros DKIM, MAIL FROM, SPF y DMARC, y la entrega de los correos.
- La cuota de concurrencia de una cuenta nueva, el despliegue sin concurrencia reservada y las conexiones a la base de datos bajo carga.
- Volver atrás: una imagen por su resumen, la aplicación web por sus versiones, un stack por una etiqueta de versión anterior y un stack en `UPDATE_ROLLBACK_FAILED`. Desmontarlo, con lo que conserva.
- El costo, que [cost.md](cost.md) solo estima.
