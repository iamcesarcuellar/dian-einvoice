# Cómo contribuir

[English](CONTRIBUTING.md) · **Español**

<!-- Traducción de CONTRIBUTING.md con SHA-256 39b6d270c5133a1d9260fc5cc1d6bfe85f1b92c88215b8e95a5200354f0b3f52. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Gracias por tomarte el tiempo. dian-einvoice es un proyecto personal de referencia que mantiene una sola persona. Por eso, a un cambio se le pide que cada afirmación del README siga siendo cierta y cada revisión siga en verde.

## Antes de empezar

- Para todo lo que vaya más allá de una corrección pequeña, abre primero un issue, para que acordemos el enfoque antes de que le dediques tiempo.
- Reporta los problemas de seguridad en privado, nunca en un issue público: mira [SECURITY.es.md](SECURITY.es.md).
- Todo corre en Docker Compose a través del Makefile, y no se instala nada en el equipo. `make help` lista los objetivos, y `make demo` arranca el stack local con los datos de ejemplo sintéticos. [docs/es/README.md](docs/es/README.md) indexa la documentación.

## Reglas del repositorio

- **La DIAN siempre es el simulador local** (`simulator/`), y AWS siempre es el emulador local (`make cloud-up`). Un cambio nunca habla con la DIAN real, nunca necesita una cuenta de AWS y no cuesta nada correrlo.
- **Solo datos sintéticos.** Cada persona, empresa, NIT y documento de los datos de ejemplo, de las pruebas y de la documentación es inventado y está marcado como tal. Los ejemplos se ambientan en Medellín, Antioquia. Nunca nombres una empresa real como usuaria, cliente o inspiración del proyecto.
- **Multiempresa.** Cada tabla del negocio lleva `company_id`, y cada consulta se limita a la empresa actual. Un cambio en una consulta la mantiene limitada y viene con una prueba de aislamiento.
- **La aplicación web y el backend van por separado.** Solo se hablan por la API HTTP bajo `/api/v1`.
- **Los recursos de la nube son código.** Los recursos compartidos y con estado, y los roles de IAM, viven en la app de CDK (`infra/cdk`). Las funciones y sus disparadores viven en el servicio serverless (`infra/functions`). El código de la aplicación solo llega a AWS a través de los adaptadores que hay detrás de sus interfaces.
- **Inglés en el código, en los comentarios y en los mensajes de commit.**
- **La documentación, en inglés y en español.** El inglés se escribe primero, y es la fuente. Cada documento tiene su traducción al español con el mismo nombre: en docs/es, en docs/es/runbooks y, en la raíz, como los archivos .es. Las traducciones usan los términos del glosario de [docs/es/README.md](docs/es/README.md#la-documentación-en-español). Un cambio en un documento lleva su traducción en el mismo commit: `make docs-check` falla cuando una traducción se desactualiza frente a su original, deja por fuera su código, sus enlaces, sus cifras o su estructura, o conserva un párrafo en inglés.
- **Sellar una traducción.** Cada traducción escrita a mano lleva el SHA-256 de su original, en un comentario bajo su línea de cambio de idioma (NOTICE.es.txt, en su última línea). Cuando la traducción ya dice lo que dice el original, `make docs-stamp` escribe el nuevo SHA-256 en cada traducción cuyo código, enlaces, cifras y estructura coinciden con los de su original, y en ninguna otra.
- **La referencia de la API en español.** Un cambio en el código de la API o en su PHPDoc cambia la referencia: `make openapi` vuelve a escribir openapi.json y openapi.es.json, y `make test-backend` falla hasta que los dos coincidan con el código. Un texto en inglés de la referencia que sea nuevo o que cambie necesita su español en `backend/resources/api-reference/es.json`, una entrada por cada párrafo, elemento de lista, fila de tabla o encabezado. `make docs-check` nombra cada bloque que se quede sin la suya.
- **Playwright se actualiza en bloque.** `@playwright/test` en `e2e/package.json` y la imagen de Playwright en `compose.yaml` deben tener la misma versión, así que Dependabot no propone ninguno de los dos: sube los dos a mano, en un solo cambio.

## Revisiones

Corre las revisiones de las partes que tocaste. La integración continua corre los mismos objetivos (`.github/workflows/ci.yml`):

```sh
make lint            # formateadores y linters; los enlaces, objetivos, archivos, imágenes, diagramas y traducciones de la documentación; la estimación de costos
make analyse         # PHPStan nivel 10, vue-tsc y tsc, en modo estricto
make test            # pruebas del backend, de la aplicación web, del simulador, de la infraestructura y de las herramientas
make demo && make e2e && make checks   # las revisiones incluyen los ejemplos de los dos README y de las dos guías de la API
make security        # auditorías de las dependencias, las licencias, los archivos de terceros (NOTICE) y la búsqueda de secretos
make cdk-check       # síntesis de las dos etapas, cdk-nag, cfn-lint y actionlint
```

Un comportamiento nuevo viene con una prueba o una revisión. `make fix` aplica los formateadores.

## Commits y pull requests

- Un tema por pull request, con una descripción corta de lo que cambió y de cómo lo verificaste.
- Los mensajes de commit siguen la forma `type: summary`, en imperativo (`feat:`, `fix:`, `docs:`, `test:`, `style:`), como en el historial.
- Al contribuir, aceptas que tu contribución queda bajo la [licencia MIT](LICENSE) del proyecto.
