# El verificador de origen no puede leer su secreto: `origin-verifier-errors`

[English](../../runbooks/origin-verifier.md) · **Español**

<!-- Traducción de docs/runbooks/origin-verifier.md con SHA-256 0c18eeb0ac82f4f995f7bc14e8f00e8ec496d61e6802c72a5efe08ecd8aed993. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `origin-verifier-errors`: el verificador de origen no pudo leer el secreto del borde (el filtro de métricas `OriginVerifierFailures`, 1 minuto) |
| Objetivo de servicio | Disponibilidad de la API |
| En el emulador | El filtro de métricas y su alarma corren de verdad. |

## Qué significa y a quién afecta

El verificador de origen es el autorizador de cada ruta de API Gateway. Compara la cabecera `X-Origin-Verify` que envía el borde con los valores actual y anterior del secreto `dian-einvoice/prod/edge/origin-verify`. Guarda durante 30 s los valores que lee, y los vuelve a leer antes, no más de una vez cada 5 s, cuando una petición trae un valor que no tiene.

Esta vez no pudo leerlo. Se queda con los valores que ya tiene, así que un verificador en caliente sigue dejando pasar las peticiones del borde. Un entorno de ejecución nuevo del verificador, en cambio, no tiene con qué comparar. API Gateway guarda cada respuesta del autorizador durante 5 minutos. Pasado ese tiempo, las peticiones que llegan por el borde pueden ser rechazadas (403) o fallar (500).

Afecta a todos los usuarios de la aplicación web y a todas las integraciones. Las peticiones que se saltan el borde se rechazan, como siempre.

## Confírmalo

1. **Qué registró el verificador**: la línea `edge.secret` con su error.
   - Emulador: `make cloud-errors FN=origin-verifier MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-origin-verifier --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "edge.secret" }'`
2. **El secreto existe y se puede leer**: no está borrado ni programado para borrarse, y su versión actual está en su lugar.
   - Emulador: el secreto en la consola web del emulador
   - AWS: `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/edge/origin-verify --query "[DeletedDate,VersionIdsToStages]"`
3. **El borde responde**, y API Gateway rechaza las peticiones que se lo saltan.
   - Emulador: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`

## Causas probables

- **No hay conexión con Secrets Manager** desde el verificador, que corre fuera de la VPC y usa el endpoint público: un evento regional de AWS.
- **El secreto se borró** o quedó programado para borrarse, a mano o por un stack (lo guarda el stack security).
- **El rol del verificador** perdió su permiso sobre el secreto (cambió el stack compute).
- **Throttling de Secrets Manager**: muchos entornos nuevos del verificador arrancan a la vez, y cada uno lee el secreto.

## Responde

4. **Un secreto programado para borrarse**: restáuralo. Recupera sus valores tal como estaban, así que la distribución y el verificador vuelven a coincidir.
   - Emulador: ninguno; el emulador conserva los secretos del stack security.
   - AWS: `aws secretsmanager restore-secret --secret-id dian-einvoice/prod/edge/origin-verify`
5. **Cambió un rol o un stack**: despliega los stacks desde el último commit desplegado, lo que restablece la política. El flujo de despliegue muestra primero las diferencias.
6. **La distribución y el verificador no coinciden en el valor** (una rotación a medias): termina la rotación (mira "El secreto del borde" en [rotate-secrets.md](rotate-secrets.md)).

## Verifica la recuperación

7. **El borde responde**, el verificador ya no registra fallas de `edge.secret` y la alarma vuelve a `OK`.
   - Emulador: `make cloud-deploy STEP=smoke`, luego `make cloud-alarms`
   - AWS: `sh scripts/deploy.sh prod smoke`, luego `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-origin-verifier-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Si alguien borró el secreto a mano: solo CloudFormation y el procedimiento de rotación pueden tocarlo.
- Los rechazos que les llegaron a los usuarios se descuentan del presupuesto de errores de la API.
