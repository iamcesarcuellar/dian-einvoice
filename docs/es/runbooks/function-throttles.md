# Funciones con throttling: `function-throttles`

[English](../../runbooks/function-throttles.md) · **Español**

<!-- Traducción de docs/runbooks/function-throttles.md con SHA-256 301d403e0250ddd2b0d2e6845a857a2c4be7425264dcb3035231730148a1ceb3. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

| | |
| --- | --- |
| Alarma | `function-throttles`: una o más invocaciones de cualquier función rechazadas por throttling en 5 minutos (métricas calculadas sobre la métrica `Throttles` de cada función) |
| Objetivos de servicio | Disponibilidad de la API, Latencia de la API |
| En el emulador | El emulador no evalúa ni las métricas calculadas ni las métricas nativas de Lambda. Sí aplica la concurrencia reservada, y `make cloud-functions` muestra cada límite. |

## Qué significa y a quién afecta

Lambda rechazó invocaciones porque una función ya tenía corriendo todos los entornos de ejecución que se le permiten. El límite es la concurrencia reservada de la función: api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, verificador de origen 10, notificador 2. Cuando la etapa se despliega sin reservar (`reservedConcurrency: unreserved`, docs/es/deployment.md), el límite es la cuota de ejecuciones concurrentes de la cuenta.

A quién afecta:

- **La API**: las peticiones reciben un 429 o un 503 a través del borde.
- **Las colas**: los mensajes esperan más.
- **El relay o el programador**: se salta un minuto.

La concurrencia reservada también pone un tope a las conexiones a la base de datos: 60 en total, de las 135 que acepta la base de datos con 4 ACU. Por eso, subirla también es una decisión sobre la base de datos.

## Confírmalo

1. **Qué función es y cuál es su límite.**
   - Emulador: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, luego `aws lambda get-function-concurrency --function-name dian-einvoice-prod-<function>`
2. **La concurrencia de la cuenta**: su cuota, y cuánta queda sin reservar (deben quedar al menos 100).
   - Emulador: `make cloud-functions` (su última línea)
   - AWS: `aws lambda get-account-settings --query AccountLimit`, y `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384`
3. **Tráfico o lentitud**: más peticiones que de costumbre, o las mismas peticiones, pero más lentas. Cada entorno queda ocupado mientras corre su invocación. Revisa el widget "Function duration, p95 (ms)" del tablero y los widgets "Concurrency" de cada función, y, para la API, la consulta guardada `dian-einvoice/prod/slow-requests`.

## Causas probables

- **Más tráfico**: una integración que emite de forma masiva, o una empresa que creció. O una avalancha de peticiones: el firewall cuenta y bloquea las avalanchas por dirección antes de que lleguen a la API.
- **Invocaciones más lentas**: la base de datos ([database-capacity.md](database-capacity.md)), una dependencia que espera hasta agotar su tiempo de espera, o arranques en frío después de un despliegue.
- **Una función que se cuelga** hasta agotar su tiempo de espera retiene sus entornos. El relay, con un límite de 1, se bloquea a sí mismo.
- **Una cuota baja en la cuenta**, con las funciones desplegadas sin concurrencia reservada. Las cuentas nuevas empiezan con cuotas reducidas, que AWS sube con el uso.

## Responde

4. **Primero, la lentitud**: corrige lo que vuelve lentas las invocaciones ([api-latency.md](api-latency.md), [database-capacity.md](database-capacity.md)). Más concurrencia para invocaciones lentas solo ocupa más conexiones.
5. **Tráfico legítimo que se mantiene**: sube la concurrencia reservada de la función en el servicio serverless (`custom.concurrency.reserved` en `infra/functions/serverless.yml`) y luego despliega las funciones. No te salgas del presupuesto de conexiones de la base de datos: una prueba vigila que la suma quede por debajo de las conexiones que acepta la base de datos. Más allá de unos 95 entornos de la API con 4 ACU, primero activa RDS Proxy o sube la capacidad de la base de datos (docs/es/infrastructure.md).
   - Emulador: `make cloud-deploy STEP=functions`
   - AWS: el flujo de despliegue desde el commit con el cambio, o `sh scripts/deploy.sh prod functions <the image the functions run>`
6. **La cuota de la cuenta**: pídele a AWS más ejecuciones concurrentes. Vuelve a desplegar con concurrencia reservada cuando la cuota deje 100 sin reservar por encima de la suma que reservan las funciones (docs/es/deployment.md).
   - Emulador: ninguno; el emulador no tiene cuotas.
   - AWS: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`

## Verifica la recuperación

7. **Ya no hay throttling**, y la concurrencia de cada función está por debajo de su límite.
   - Emulador: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of 15 minutes ago> --end-time <ISO time now> --period 300 --statistics Sum`
8. **La alarma vuelve a `OK`.**
   - Emulador: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-throttles --query "MetricAlarms[].[StateValue,StateReason]"`

## Después

- Un límite nuevo es una decisión sobre la base de datos: escribe la suma nueva, y su razón, junto a la prueba que la hace cumplir.
- Un put-function-concurrency a mano es una desviación: el siguiente despliegue vuelve a poner el valor de la plantilla.
