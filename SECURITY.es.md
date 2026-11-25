# Política de seguridad

[English](SECURITY.md) · **Español**

<!-- Traducción de SECURITY.md con SHA-256 b73c79965363d1b98cc765d7fff924004f0dc30feeab9ccce1071382160e033b. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

dian-einvoice es una implementación de referencia que corre en tu máquina. Un simulador local hace las veces de la DIAN, un emulador local hace las veces de los servicios de AWS, y todas las personas y empresas de los datos de ejemplo son sintéticas. Nunca se desplegó nada en una cuenta real de AWS.

Aun así, un reporte es bienvenido cuando muestra una forma de romper lo que promete el código:

- el aislamiento entre una empresa y otra;
- las sesiones y las llaves de API;
- las firmas de los documentos y de los webhooks;
- los secretos;
- la infraestructura como código que se desplegaría en una cuenta real.

## Cómo reportar una vulnerabilidad

Repórtala en privado a través de GitHub: abre la pestaña **Security** de este repositorio y elige **Report a vulnerability**. El reporte queda entre tú y el mantenedor hasta que se publique una corrección. Por favor, no abras nada público sobre ella: ni un issue, ni un pull request, ni una discusión.

Un reporte útil incluye:

- el commit que probaste (`git rev-parse HEAD`) y el entorno: el stack local (`make demo`) o la nube emulada (`make cloud-demo`);
- los pasos para reproducirla, con las peticiones o los comandos, y lo que esperabas que pasara;
- lo que gana un atacante y lo que necesita de antemano (una cuenta, una llave de API de solo lectura o de lectura y escritura, un rol en una empresa).

No hay programa de recompensas (bug bounty). Este es un proyecto personal que mantiene una sola persona. El mantenedor lee cada reporte y responde en el aviso de seguridad. Un problema confirmado se corrige en `main`, y el aviso te da el crédito, a menos que prefieras otra cosa.

## Versiones con soporte

La última versión, la 1.1.1, y el último commit de `main`. Una corrección llega primero a `main` y luego a la siguiente versión ([CHANGELOG.es.md](CHANGELOG.es.md)).

## Dentro del alcance

- El backend (`backend/`):
  - la autenticación, las sesiones y CSRF, y los roles;
  - el aislamiento entre empresas (cada tabla del negocio se limita a su empresa);
  - las llaves de API, la idempotencia, los límites de peticiones, y los webhooks y sus firmas;
  - la subida de certificados, el almacén de llaves, la generación del PDF y del XML, y la entrega por correo.
- La aplicación web (`frontend/`): cross-site scripting, el manejo de las sesiones y cualquier cosa que filtre los datos de otra empresa.
- La infraestructura como código (`infra/cdk`, `infra/functions`):
  - políticas de IAM más amplias de lo que necesitan sus funciones;
  - un recurso al que se puede llegar desde donde no se debería;
  - la verificación de origen del borde y las cabeceras de seguridad;
  - los secretos y su rotación;
  - el flujo de despliegue (`.github/workflows/deploy.yml`) y su confianza en el OpenID Connect de GitHub.
- Los scripts y la configuración de Docker: un paso que expondría un secreto, publicaría un puerto más allá de `127.0.0.1` o tocaría lo que pertenece a otro proyecto de la misma máquina.

## Fuera del alcance

- La DIAN y sus servicios. Este proyecto nunca habla con ellos: el simulador local (`simulator/`) hace sus veces, y es una herramienta de desarrollo.
- Las herramientas de desarrollo del stack local, que corren sin autenticación en `127.0.0.1` a propósito: el buzón local, el receptor de webhooks y la API de administración del simulador. En la nube emulada, también el emulador de AWS y su consola web ([docs/es/limitations.md](docs/es/limitations.md#seguridad)).
- Los datos de ejemplo sintéticos. Sus usuarios y contraseñas (`demo@example.com` / `password`), su llave de API y sus certificados de prueba autofirmados son públicos a propósito, y solo existen para el desarrollo local. El seeder de la demo se niega a correr a menos que `APP_ENV` sea `local` o `testing`.
- Los hallazgos que necesitan un despliegue de producción que no existe, o que solo repiten una limitación que ya aparece en [docs/es/limitations.md](docs/es/limitations.md).
- Las vulnerabilidades de una dependencia a la que no hay forma de llegar a través de este proyecto: repórtalas al proyecto dueño de esa dependencia. `make security` audita las dependencias de cada aplicación.
- La denegación de servicio contra tu propio stack local, la ingeniería social y todo lo que necesite acceso físico a la máquina.

## Qué leer primero

- [docs/es/threat-model.md](docs/es/threat-model.md): los activos, los actores, las fronteras de confianza, las amenazas con sus mitigaciones y los riesgos que quedan.
- [docs/es/limitations.md](docs/es/limitations.md): lo que el proyecto no hace, o hace solo en parte, la seguridad incluida.
