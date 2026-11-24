# Rotar un secreto fuera de lo programado

[English](../../runbooks/rotate-secrets.md) · **Español**

<!-- Traducción de docs/runbooks/rotate-secrets.md con SHA-256 bc6514d690935ea6a2c498bc211fcb914442eeff8474e4d9d47f6b9d67f38e70. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Un procedimiento sin alarma. Úsalo cuando un secreto pueda haberse filtrado: en un registro, en una captura, en un portátil perdido, con una persona que se fue. Úsalo también para rotar un secreto antes de lo programado por cualquier otra razón.

Cada secreto tiene su propio procedimiento, porque cada uno llega a sus usuarios a su manera. Rota un secreto a la vez y avisa en el chat cuál y cuándo.

| Secreto | Quién lo usa | Rotación programada | Fuera de lo programado |
| --- | --- | --- | --- |
| `dian-einvoice/prod/edge/origin-verify` | La distribución lo envía a API Gateway, y el verificador de origen lo revisa | No: con este procedimiento, cuando haga falta | [abajo](#el-secreto-del-borde) |
| `dian-einvoice/prod/alerts/chat-webhook` | El notificador publica las alarmas en su dirección | No: el chat emite la dirección | [abajo](#la-dirección-del-webhook-del-chat) |
| `dian-einvoice/prod/database/application` | Todas las funciones en PHP, menos la función de consola | Cada 30 días, con usuarios alternos | [abajo](#las-credenciales-de-la-base-de-datos) |
| `dian-einvoice/prod/database/migrations` | La función de consola y las funciones de rotación | Cada 30 días, con un usuario único | [abajo](#las-credenciales-de-la-base-de-datos) |
| `dian-einvoice/prod/app` (`APP_KEY`) | Todas las funciones en PHP: cifra los valores guardados | No | No se admite: primero habría que volver a cifrar cada valor cifrado (docs/es/limitations.md) |

## El secreto del borde

El verificador de origen acepta tanto la versión actual como la anterior del secreto. Así que una rotación no causa ninguna caída, siempre que una segunda rotación no descarte un valor que alguna ubicación de borde todavía envíe. El verificador de origen se explica en docs/es/infrastructure.md.

1. **Pon un valor nuevo como versión actual.** El valor que reemplaza sigue siendo válido como versión anterior. Anota el id de la versión que trae la respuesta.
   - Emulador: `make cloud-rotate SECRET=edge` hace los pasos 1 a 4: el valor nuevo, el stack edge desplegado con él y la prueba de humo.
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/edge/origin-verify --secret-string "$(openssl rand -hex 32)"`
2. **Espera 30 s**, el tiempo que el verificador guarda los valores. Si le llega un valor que no tiene, vuelve a leer el secreto enseguida.
3. **Despliega el borde con esa versión.** CloudFormation resuelve el valor nuevo, y CloudFront lo envía a medida que cada ubicación de borde toma la configuración nueva. Mientras tanto, las demás ubicaciones de borde envían el valor anterior, que sigue siendo válido.
   - Emulador: parte de `make cloud-rotate SECRET=edge`; por separado, `make cloud-deploy STEP=edge`
   - AWS: `ORIGIN_VERIFY_VERSION=<version id> sh scripts/deploy.sh prod edge` con las variables de la cuenta (docs/es/deployment.md), o el flujo de despliegue.
4. **Espera a que la distribución quede desplegada**, y 5 minutos más por la caché del autorizador, antes de cualquier otra rotación. Luego revisa que el borde responda y que API Gateway rechace las peticiones que se lo saltan.
   - Emulador: parte de `make cloud-rotate SECRET=edge`; por separado, `make cloud-deploy STEP=smoke`
   - AWS: `aws cloudfront wait distribution-deployed --id "$(aws ssm get-parameter --name /dian-einvoice/prod/edge/distribution-id --query Parameter.Value --output text)"`, luego `sh scripts/deploy.sh prod smoke`
5. **Si el valor viejo se filtró**, rota una vez más después del paso 4. La segunda rotación lo saca también de la versión anterior.

## La dirección del webhook del chat

La dirección es una credencial: quien la tenga puede publicar en el chat de los operadores.

1. **Pídele al chat una dirección nueva**, y revoca allí la vieja.
2. **Guárdala en el secreto.** El notificador la lee en 5 minutos o menos, y nunca la registra.
   - Emulador: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
3. **Revisa que llegue una notificación** después de 5 minutos: [notifier.md](notifier.md), paso 7.

## Las credenciales de la base de datos

Las funciones de rotación de AWS rotan las dos identidades cada 30 días. Para rotar fuera de lo programado, pídele a Secrets Manager que rote ya.

- **La identidad de la aplicación** alterna entre dos usuarios. Así que las credenciales que tiene una función en caliente siguen funcionando hasta la rotación que viene después de la próxima. Cuando la base de datos rechaza una contraseña, el conector de la aplicación vuelve a leer su secreto, una vez. Las dos identidades de la base de datos se explican en docs/es/infrastructure.md.
- **La identidad de las migraciones** tiene un solo usuario. Durante su rotación, la base de datos rechaza a la función de consola en los segundos que pasan entre el cambio de la contraseña y el del secreto, así que rótala cuando no esté corriendo ninguna operación.

1. **Rota ya la identidad de la aplicación**, y una vez más si su contraseña vieja pudo filtrarse. La segunda rotación cambia la contraseña del usuario que la primera dejó de usar.
   - Emulador: `make cloud-rotate SECRET=database-application` (el emulador no puede correr las funciones de rotación de AWS, así que la función de consola hace sus pasos, dos veces, con peticiones y facturas antes y después de cada una)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/application`, luego `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/database/application --query "[LastRotatedDate,VersionIdsToStages]"` hasta que la versión nueva sea `AWSCURRENT`
2. **Rota ya la identidad de las migraciones.**
   - Emulador: `make cloud-rotate SECRET=database-migrations` (pone una contraseña nueva en el secreto, se la asigna al usuario como lo hace la rotación de usuario único, y lee con ella el estado de las migraciones)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/migrations`
3. **Revisa las funciones**: la API responde, la cadena avanza y `SecretRereads` cuenta una relectura por entorno en caliente, no más.
   - Emulador: `make cloud-deploy STEP=smoke`, luego `make cloud-artisan ARGS="db:connections"`
   - AWS: `sh scripts/deploy.sh prod smoke`, y `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
4. **Una rotación que falla** la reintenta Secrets Manager en su siguiente ventana, y mientras tanto las credenciales viejas siguen funcionando. Su error está en el grupo de registros de su función de rotación.

## Después

- Por dónde se filtró el secreto y cómo evitar que se vuelva a filtrar. Un secreto en un registro quiere decir que el redactor no lo enmascaró: agrega su forma a `App\Support\Observability\Redactor` y al barrido de `make cloud-check`.
