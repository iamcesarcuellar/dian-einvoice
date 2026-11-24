# Licencias de terceros

[English](../third-party-licenses.md) · **Español**

<!-- Generado por make licenses (scripts/licenses.sh, scripts/security/third-party.mjs). No lo edites: make security falla cuando este archivo no es lo que dan los archivos de bloqueo. -->

Las dependencias de producción de las cinco aplicaciones de este repositorio, como las instalan sus archivos de bloqueo: 472 paquetes, cada uno con la licencia que declara. Un paquete instalado en más de una versión aparece una sola vez, con la licencia de cada versión, y las versiones mismas están en los archivos de bloqueo. Las herramientas de desarrollo (linters, ejecutores de pruebas, el navegador de la prueba de punta a punta) no se distribuyen con el código y no se listan. Tampoco las imágenes de contenedor del stack local, que corren como procesos aparte. Los archivos de otros que viven en el propio repositorio vienen [después del resumen](#archivos-de-terceros-en-el-repositorio), y [NOTICE.es.txt](../../NOTICE.es.txt) recoge sus avisos.

Este proyecto está bajo la [licencia MIT](../../LICENSE). Cada licencia de abajo es compatible con ella, y `make security` lo revisa en cada ejecución contra la lista de `scripts/security/compatibility.mjs`:

- Las licencias **permisivas** (MIT, ISC, BSD, Apache-2.0 y similares) piden conservar su aviso, que trae cada paquete.
- Las licencias de **copyleft débil** (LGPL, EPL, MPL) cubren los archivos de su biblioteca, que aquí se usan tal como se publicaron: la biblioteca sigue bajo su licencia, y el código de este proyecto sigue siendo MIT.
- Las licencias de **fuentes tipográficas** (OFL-1.1) cubren la fuente en sí, no los documentos ni las páginas que la usan.
- Un paquete con licencias a elegir (`A OR B`) se usa bajo la primera alternativa compatible.

## Por licencia

| Licencia | Tipo | Backend | Aplicación web | Simulador de la DIAN | App de CDK | Servicio serverless |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| (MIT OR Apache-2.0) | permisiva |  | 1 |  |  |  |
| (MIT OR GPL-3.0-or-later) | permisiva |  |  |  | 1 |  |
| 0BSD | permisiva |  | 1 |  |  | 1 |
| Apache-2.0 | permisiva | 3 | 6 |  | 9 | 48 |
| BlueOak-1.0.0 | permisiva |  |  |  | 3 |  |
| BSD-2-Clause | permisiva | 2 | 4 |  |  | 2 |
| BSD-3-Clause | permisiva | 6 | 9 |  |  | 2 |
| BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | permisiva | 2 |  |  |  |  |
| EPL-2.0 | copyleft débil, como biblioteca |  | 1 |  |  |  |
| ISC | permisiva |  | 36 |  | 2 | 26 |
| LGPL-2.1 | copyleft débil, como biblioteca | 1 |  |  |  |  |
| LGPL-2.1-or-later | copyleft débil, como biblioteca | 1 |  |  |  |  |
| LGPL-3.0-or-later | copyleft débil, como biblioteca | 1 |  |  |  |  |
| MIT | permisiva | 89 | 138 | 2 | 11 | 65 |
| OFL-1.1 | fuente tipográfica |  | 1 |  |  |  |
| PSF-2.0 | permisiva |  |  |  |  | 1 |
| Python-2.0 | permisiva |  |  |  |  | 1 |
| **Paquetes** | | **105** | **194** | **2** | **26** | **145** |

4 paquetes están instalados en versiones que declaran licencias distintas, y se cuentan en cada una: d3-array (Aplicación web: BSD-3-Clause, ISC); d3-path (Aplicación web: BSD-3-Clause, ISC); d3-shape (Aplicación web: BSD-3-Clause, ISC); argparse (Servicio serverless: PSF-2.0, Python-2.0).

## Archivos de terceros en el repositorio

Estos archivos son obra de otros, y el repositorio los guarda bajo sus propios términos. [NOTICE.es.txt](../../NOTICE.es.txt) recoge sus avisos y dice que la licencia MIT de este proyecto no los cubre. `make security` revisa esta lista contra el repositorio (`scripts/security/notices.mjs`, a partir de `scripts/security/third-party-files.json`): cada archivo listado existe, el que no tenga cambios conserva su SHA-256 y su aviso, NOTICE y su traducción lo nombran, y un archivo que parece obra de un tercero, por su tipo o por un aviso de copyright o de licencia, hace fallar la revisión salvo que esté listado aquí o declarado como propio del proyecto.

8 grupos, 25 entradas:

| Archivos | Titular | Origen | Términos | Sin cambios |
| --- | --- | --- | --- | --- |
| **Esquemas de OASIS Universal Business Language (UBL) 2.1**<br>`backend/resources/dian/xsd/common/UBL-CommonAggregateComponents-2.1.xsd` (SHA-256 `939172ad8dd0…`)<br>`backend/resources/dian/xsd/common/UBL-CommonBasicComponents-2.1.xsd` (SHA-256 `bd4ad043ee1d…`)<br>`backend/resources/dian/xsd/common/UBL-CommonExtensionComponents-2.1.xsd` (SHA-256 `ad7a4e490978…`)<br>`backend/resources/dian/xsd/common/UBL-CommonSignatureComponents-2.1.xsd` (SHA-256 `3db472305f02…`)<br>`backend/resources/dian/xsd/common/UBL-CoreComponentParameters-2.1.xsd` (SHA-256 `8be3379dbdcb…`)<br>`backend/resources/dian/xsd/common/UBL-ExtensionContentDataType-2.1.xsd` (SHA-256 `fcee77a11870…`)<br>`backend/resources/dian/xsd/common/UBL-QualifiedDataTypes-2.1.xsd` (SHA-256 `7dcb156e6102…`)<br>`backend/resources/dian/xsd/common/UBL-SignatureAggregateComponents-2.1.xsd` (SHA-256 `17bb6b62d709…`)<br>`backend/resources/dian/xsd/common/UBL-SignatureBasicComponents-2.1.xsd` (SHA-256 `cef924d7ba3d…`)<br>`backend/resources/dian/xsd/common/UBL-UnqualifiedDataTypes-2.1.xsd` (SHA-256 `09052d406b42…`)<br>`backend/resources/dian/xsd/maindoc/UBL-Invoice-2.1.xsd` (SHA-256 `40fae8cb436f…`)<br>`backend/resources/dian/xsd/maindoc/UBL-CreditNote-2.1.xsd` (SHA-256 `21eee9df7418…`)<br>`backend/resources/dian/xsd/maindoc/UBL-DebitNote-2.1.xsd` (SHA-256 `ea5303c2191d…`)<br>`backend/resources/dian/xsd/maindoc/UBL-AttachedDocument-2.1.xsd` (SHA-256 `58c44a11e5ec…`) | OASIS Open | UBL 2.1 OS (4 de noviembre de 2013), tal como viene en la caja de herramientas de la DIAN v1.9 (Caja de herramientas FE - V1.9 - (v2026-2)). 11 de estos archivos son idénticos, byte por byte, a los de la distribución de OASIS (docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.zip). Los esquemas de la nota crédito, la nota débito y el AttachedDocument son de la edición CS1 (16 de junio de 2013): sus definiciones son iguales a las de la edición OS, y solo cambian los comentarios que nombran la edición. [Fuente](https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip). | El aviso de copyright de OASIS: se pueden hacer y distribuir copias siempre que conserven el aviso y que los esquemas no se modifiquen. NOTICE reproduce el aviso. | Sí |
| **Esquema de XML Signature, en su copia de UBL 2.1**<br>`backend/resources/dian/xsd/common/UBL-xmldsig-core-schema-2.1.xsd` (SHA-256 `101909c9f064…`) | The Internet Society y el W3C | El esquema xmldsig-core-schema.xsd del W3C, que OASIS copió en UBL 2.1 OS quitándole los identificadores PUBLIC y SYSTEM de su DOCTYPE, como dice el archivo; la caja de herramientas de la DIAN v1.9 trae esa copia, idéntica byte por byte a la de la distribución de OASIS. [Fuente](https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip). | La W3C Software License que nombra el archivo (http://www.w3.org/Consortium/Legal/copyright-software-19980720): permite redistribuirlo con el aviso que conserva el archivo. | Sí |
| **Esquemas de XAdES, en sus copias de UBL 2.1**<br>`backend/resources/dian/xsd/common/UBL-XAdESv132-2.1.xsd` (SHA-256 `a4f726bcf8cc…`)<br>`backend/resources/dian/xsd/common/UBL-XAdESv141-2.1.xsd` (SHA-256 `1fa4625e9cef…`) | ETSI | Los esquemas de XAdES 1.3.2 y 1.4.1 del ETSI (uri.etsi.org/01903), que OASIS copió en UBL 2.1 OS cambiando solo la URI desde la que importan XML Signature, como dice cada archivo; la caja de herramientas de la DIAN v1.9 trae esas copias, idénticas byte por byte a las de la distribución de OASIS. [Fuente](https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip). | Se redistribuyen tal como OASIS los distribuye en UBL 2.1, con el comentario que nombra su origen en el ETSI. | Sí |
| **Módulo de esquema Core Component Type de UN/CEFACT**<br>`backend/resources/dian/xsd/common/CCTS_CCT_SchemaModule-2.1.xsd` (SHA-256 `dd546e4809df…`) | UN/CEFACT | El módulo de esquema CCTS de UBL 2.1 OS, tal como viene en la caja de herramientas de la DIAN v1.9, idéntico byte por byte al de la distribución de OASIS. [Fuente](https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip). | El aviso de UN/CEFACT en el archivo: se puede copiar y distribuir con el aviso, y no se puede modificar. | Sí |
| **Esquema de las extensiones de la DIAN a UBL 2.1**<br>`backend/resources/dian/xsd/maindoc/DIAN_UBL_Structures.xsd` (SHA-256 `41ead5af3ea4…`) | DIAN (Dirección de Impuestos y Aduanas Nacionales de Colombia) | XSD/maindoc/DIAN_UBL_Structures.xsd de la caja de herramientas de la DIAN v1.9 (Caja de herramientas FE - V1.9 - (v2026-2)), publicada con el anexo técnico de la factura electrónica de venta, versión 1.9, que adopta la Resolución 000165 del 1 de noviembre de 2023. [Fuente](https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip). | Hace parte del anexo de un acto administrativo: el artículo 41 de la Ley 23 de 1982 permite a cualquiera reproducir los actos administrativos siempre que la copia se ajuste a la edición oficial. Se conserva byte por byte como lo trae la caja de herramientas. | Sí |
| **Fuentes Inter 4.1**<br>`backend/resources/fonts/inter/Inter-Regular.ttf` (SHA-256 `40d692fce188…`)<br>`backend/resources/fonts/inter/Inter-Medium.ttf` (SHA-256 `97ad806f526e…`)<br>`backend/resources/fonts/inter/Inter-SemiBold.ttf` (SHA-256 `78a843fade9d…`)<br>`backend/resources/fonts/inter/LICENSE.txt` (SHA-256 `262481e84452…`) | The Inter Project Authors | extras/ttf/Inter-Regular.ttf, Inter-Medium.ttf e Inter-SemiBold.ttf y LICENSE.txt de la versión 4.1 de Inter (zip con SHA-256 9883fdd4a49d4fb66bd8177ba6625ef9a64aa45899767dde3d36aa425756b11e), idénticos byte por byte. El renderizador de PDF incrusta copias cuyos dígitos apuntan a las cifras tabulares de la misma fuente (backend/app/Modules/Delivery/Pdf/TabularFigures.php); esas copias solo existen en memoria y en los PDF. [Fuente](https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip). | SIL Open Font License 1.1 (LICENSE.txt junto a las fuentes): las fuentes se pueden empaquetar y redistribuir con software; la licencia cubre las fuentes, no los documentos hechos con ellas. | Sí |
| **Códigos de municipios DIVIPOLA del DANE**<br>`backend/app/Modules/Reference/Data/divipola.json` | Departamento Administrativo Nacional de Estadística (DANE) | Los 1.122 registros del conjunto de datos "DIVIPOLA- Códigos municipios" (gdxc-w37w) de datos.gov.co, actualizado por el DANE el 24 de enero de 2025 y descargado el 18 de noviembre de 2026. Los registros están tal como los entrega el portal (idénticos a sus datos del 22 de noviembre de 2026); el archivo los envuelve con la fuente, las fechas y el conteo. [Fuente](https://www.datos.gov.co/resource/gdxc-w37w.json). | Creative Commons Attribution-ShareAlike 4.0 International, la licencia que el portal le da al conjunto de datos: con atribución al DANE, y el archivo y sus adaptaciones quedan bajo la misma licencia. | No |
| **Componentes de shadcn-vue**<br>`frontend/src/components/ui/**` | unovue | Los componentes de interfaz de shadcn-vue (estilo reka-vega, frontend/components.json): su CLI los copió en la aplicación web, y luego se formatearon con la configuración de Prettier del proyecto y se adaptaron en algunos puntos. [Fuente](https://github.com/unovue/shadcn-vue). | Licencia MIT, Copyright (c) 2023 unovue; NOTICE la reproduce. | No |

### Fragmentos en archivos de este proyecto

- **Listas de códigos, reglas, mensajes y ejemplos del anexo técnico 1.9 de la DIAN.** Los códigos y los nombres oficiales de las tablas de la sección 13, los códigos y los mensajes de las reglas de validación de las secciones 8 y 12 (en español, como los escribe el anexo) y los valores de los ejemplos de CUFE y CUDE de la sección 11, dentro de los archivos de este proyecto. En `backend/app/Modules/Reference/Enums/*.php`, `simulator/src/rules.ts`, `backend/app/Modules/Dian/Console/DianCheckCommand.php`, `backend/tests/Unit/Dian/DocumentKeyTest.php`. Hacen parte del anexo que adopta la Resolución 000165 de 2023, y se reproducen como se publicaron (artículo 41 de la Ley 23 de 1982).

### Lo que queda fuera del repositorio

- **La caja de herramientas de la DIAN v1.9 (schematron, listas de códigos, ejemplos, el anexo).** make dian-toolbox la descarga de la DIAN en backend/storage/app/dian-toolbox, que nunca entra en un commit, y revisa su SHA-256; solo make dian-schematron la necesita.
- **La política de firma de la DIAN (politicadefirmav2.pdf).** Nunca se descarga: cada firma nombra su URL y su resumen SHA-256, la misma que traen los ejemplos oficiales (backend/app/Modules/Dian/Signing/XadesSigner.php).
- **Las autoridades de certificación de Amazon RDS (global-bundle.pem).** Se descargan en la imagen de las funciones cuando se construye, fijadas con su SHA-256 (backend/Dockerfile).
- **Stoplight Elements 8.4.2.** El navegador lo carga desde unpkg.com para la referencia de la API, fijado con Subresource Integrity (backend/resources/http/security-headers.json).
- **SaxonJS 2.7.0 y SchXslt 1.10.1.** make dian-schematron los descarga en un volumen de Docker (scripts/dian-schematron/run.sh).
- **`backend/resources/dian/examples/invoice-example.xml`**, retirado: "Ejemplificación Impuesto especial para la estabilidad fiscal.xml", una factura de ejemplo de la caja de herramientas de la DIAN v1.9. Material de apoyo que nada en el repositorio lee; make dian-toolbox descarga toda la caja de herramientas, con su SHA-256, cuando una revisión la necesita.

## Backend

105 paquetes de `backend/composer.lock` (`composer licenses --no-dev`): los paquetes de PHP de la API, los workers, el relay y las funciones.

| Paquete | Licencia |
| --- | --- |
| aws/aws-crt-php | Apache-2.0 |
| aws/aws-sdk-php | Apache-2.0 |
| bacon/bacon-qr-code | BSD-2-Clause |
| bref/bref | MIT |
| bref/laravel-bridge | MIT |
| bref/laravel-health-check | MIT |
| bref/monolog-bridge | MIT |
| brick/math | MIT |
| carbonphp/carbon-doctrine-types | MIT |
| crwlr/query-string | MIT |
| dasprid/enum | BSD-2-Clause |
| dedoc/scramble | MIT |
| dflydev/dot-access-data | MIT |
| doctrine/inflector | MIT |
| doctrine/lexer | MIT |
| dompdf/dompdf | LGPL-2.1 |
| dompdf/php-font-lib | LGPL-2.1-or-later |
| dompdf/php-svg-lib | LGPL-3.0-or-later |
| dragonmantank/cron-expression | MIT |
| egulias/email-validator | MIT |
| fruitcake/php-cors | MIT |
| graham-campbell/result-type | MIT |
| guzzlehttp/guzzle | MIT |
| guzzlehttp/promises | MIT |
| guzzlehttp/psr7 | MIT |
| guzzlehttp/uri-template | MIT |
| hollodotme/fast-cgi-client | MIT |
| laminas/laminas-diactoros | BSD-3-Clause |
| laravel/framework | MIT |
| laravel/octane | MIT |
| laravel/prompts | MIT |
| laravel/sanctum | MIT |
| laravel/serializable-closure | MIT |
| laravel/tinker | MIT |
| league/commonmark | BSD-3-Clause |
| league/config | BSD-3-Clause |
| league/flysystem | MIT |
| league/flysystem-local | MIT |
| league/mime-type-detection | MIT |
| league/uri | MIT |
| league/uri-interfaces | MIT |
| masterminds/html5 | MIT |
| monolog/monolog | MIT |
| mtdowling/jmespath.php | MIT |
| myclabs/deep-copy | MIT |
| nesbot/carbon | MIT |
| nette/schema | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only |
| nette/utils | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only |
| nikic/php-parser | BSD-3-Clause |
| nunomaduro/termwind | MIT |
| nyholm/psr7 | MIT |
| phpoption/phpoption | Apache-2.0 |
| phpstan/phpdoc-parser | MIT |
| psr/clock | MIT |
| psr/container | MIT |
| psr/event-dispatcher | MIT |
| psr/http-client | MIT |
| psr/http-factory | MIT |
| psr/http-message | MIT |
| psr/http-server-handler | MIT |
| psr/log | MIT |
| psr/simple-cache | MIT |
| psy/psysh | MIT |
| ramsey/collection | MIT |
| ramsey/uuid | MIT |
| riverline/multipart-parser | MIT |
| sabberworm/php-css-parser | MIT |
| spatie/laravel-package-tools | MIT |
| symfony/clock | MIT |
| symfony/console | MIT |
| symfony/css-selector | MIT |
| symfony/deprecation-contracts | MIT |
| symfony/error-handler | MIT |
| symfony/event-dispatcher | MIT |
| symfony/event-dispatcher-contracts | MIT |
| symfony/filesystem | MIT |
| symfony/finder | MIT |
| symfony/http-foundation | MIT |
| symfony/http-kernel | MIT |
| symfony/mailer | MIT |
| symfony/mime | MIT |
| symfony/polyfill-ctype | MIT |
| symfony/polyfill-intl-grapheme | MIT |
| symfony/polyfill-intl-idn | MIT |
| symfony/polyfill-intl-normalizer | MIT |
| symfony/polyfill-mbstring | MIT |
| symfony/polyfill-php80 | MIT |
| symfony/polyfill-php82 | MIT |
| symfony/polyfill-php84 | MIT |
| symfony/polyfill-php85 | MIT |
| symfony/polyfill-php86 | MIT |
| symfony/polyfill-time | MIT |
| symfony/polyfill-uuid | MIT |
| symfony/process | MIT |
| symfony/psr-http-message-bridge | MIT |
| symfony/routing | MIT |
| symfony/service-contracts | MIT |
| symfony/string | MIT |
| symfony/translation | MIT |
| symfony/translation-contracts | MIT |
| symfony/uid | MIT |
| symfony/var-dumper | MIT |
| tijsverkoyen/css-to-inline-styles | BSD-3-Clause |
| vlucas/phpdotenv | BSD-3-Clause |
| voku/portable-ascii | MIT |

## Aplicación web

194 paquetes de `frontend/pnpm-lock.yaml` (`pnpm licenses list --prod`): empaquetados en los archivos que carga el navegador.

| Paquete | Licencia |
| --- | --- |
| @babel/helper-string-parser | MIT |
| @babel/helper-validator-identifier | MIT |
| @babel/parser | MIT |
| @babel/types | MIT |
| @floating-ui/core | MIT |
| @floating-ui/dom | MIT |
| @floating-ui/utils | MIT |
| @floating-ui/vue | MIT |
| @fontsource-variable/inter | OFL-1.1 |
| @internationalized/date | Apache-2.0 |
| @internationalized/number | Apache-2.0 |
| @intlify/core-base | MIT |
| @intlify/devtools-types | MIT |
| @intlify/message-compiler | MIT |
| @intlify/shared | MIT |
| @jridgewell/gen-mapping | MIT |
| @jridgewell/remapping | MIT |
| @jridgewell/resolve-uri | MIT |
| @jridgewell/sourcemap-codec | MIT |
| @jridgewell/trace-mapping | MIT |
| @lucide/vue | ISC |
| @mapbox/jsonlint-lines-primitives | MIT |
| @mapbox/point-geometry | ISC |
| @mapbox/tiny-sdf | BSD-2-Clause |
| @mapbox/unitbezier | BSD-2-Clause |
| @mapbox/vector-tile | BSD-3-Clause |
| @maplibre/geojson-vt | ISC |
| @maplibre/maplibre-gl-style-spec | ISC |
| @maplibre/mlt | (MIT OR Apache-2.0) |
| @maplibre/vt-pbf | MIT |
| @oxc-project/types | MIT |
| @rolldown/binding-linux-x64-musl | MIT |
| @rolldown/pluginutils | MIT |
| @swc/helpers | Apache-2.0 |
| @tanstack/match-sorter-utils | MIT |
| @tanstack/query-core | MIT |
| @tanstack/virtual-core | MIT |
| @tanstack/vue-query | MIT |
| @tanstack/vue-virtual | MIT |
| @types/d3-array | MIT |
| @types/d3-axis | MIT |
| @types/d3-brush | MIT |
| @types/d3-chord | MIT |
| @types/d3-color | MIT |
| @types/d3-drag | MIT |
| @types/d3-ease | MIT |
| @types/d3-force | MIT |
| @types/d3-geo | MIT |
| @types/d3-hierarchy | MIT |
| @types/d3-interpolate | MIT |
| @types/d3-path | MIT |
| @types/d3-sankey | MIT |
| @types/d3-scale | MIT |
| @types/d3-selection | MIT |
| @types/d3-shape | MIT |
| @types/d3-time | MIT |
| @types/d3-timer | MIT |
| @types/d3-transition | MIT |
| @types/d3-zoom | MIT |
| @types/estree | MIT |
| @types/geojson | MIT |
| @types/leaflet | MIT |
| @types/stylis | MIT |
| @types/supercluster | MIT |
| @types/three | MIT |
| @types/throttle-debounce | MIT |
| @types/topojson | MIT |
| @types/topojson-client | MIT |
| @types/topojson-server | MIT |
| @types/topojson-simplify | MIT |
| @types/topojson-specification | MIT |
| @types/web-bluetooth | MIT |
| @unovis/dagre-layout | MIT |
| @unovis/graphlibrary | MIT |
| @unovis/ts | Apache-2.0 |
| @unovis/vue | Apache-2.0 |
| @vue-macros/common | MIT |
| @vue/compiler-core | MIT |
| @vue/compiler-dom | MIT |
| @vue/compiler-sfc | MIT |
| @vue/compiler-ssr | MIT |
| @vue/devtools-api | MIT |
| @vue/devtools-kit | MIT |
| @vue/devtools-shared | MIT |
| @vue/reactivity | MIT |
| @vue/runtime-core | MIT |
| @vue/runtime-dom | MIT |
| @vue/server-renderer | MIT |
| @vue/shared | MIT |
| @vueuse/core | MIT |
| @vueuse/metadata | MIT |
| @vueuse/shared | MIT |
| acorn | MIT |
| aria-hidden | MIT |
| ast-kit | MIT |
| ast-walker-scope | MIT |
| bidi-js | MIT |
| birpc | MIT |
| chokidar | MIT |
| class-variance-authority | Apache-2.0 |
| clsx | MIT |
| cn | MIT |
| commander | MIT |
| confbox | MIT |
| csstype | MIT |
| d3-array | BSD-3-Clause, ISC |
| d3-axis | ISC |
| d3-brush | ISC |
| d3-chord | ISC |
| d3-color | ISC |
| d3-dispatch | ISC |
| d3-drag | ISC |
| d3-ease | BSD-3-Clause |
| d3-force | ISC |
| d3-format | ISC |
| d3-geo | ISC |
| d3-geo-projection | ISC |
| d3-hierarchy | ISC |
| d3-interpolate | ISC |
| d3-path | BSD-3-Clause, ISC |
| d3-quadtree | ISC |
| d3-sankey | BSD-3-Clause |
| d3-scale | ISC |
| d3-selection | ISC |
| d3-shape | BSD-3-Clause, ISC |
| d3-time | ISC |
| d3-time-format | ISC |
| d3-timer | ISC |
| d3-transition | ISC |
| d3-zoom | ISC |
| defu | MIT |
| earcut | ISC |
| elkjs | EPL-2.0 |
| entities | BSD-2-Clause |
| estree-walker | MIT |
| exsolve | MIT |
| fdir | MIT |
| gl-matrix | MIT |
| hookable | MIT |
| internmap | ISC |
| json-stringify-pretty-compact | MIT |
| kdbush | ISC |
| leaflet | BSD-2-Clause |
| local-pkg | MIT |
| lodash-es | MIT |
| magic-string | MIT |
| magic-string-ast | MIT |
| maplibre-gl | BSD-3-Clause |
| minimist | MIT |
| mlly | MIT |
| muggle-string | MIT |
| murmurhash-js | MIT |
| nanoid | MIT |
| nostics | MIT |
| ohash | MIT |
| pathe | MIT |
| pbf | BSD-3-Clause |
| perfect-debounce | MIT |
| picocolors | ISC |
| picomatch | MIT |
| pinia | MIT |
| pkg-types | MIT |
| postcss | MIT |
| potpack | ISC |
| protocol-buffers-schema | MIT |
| quansync | MIT |
| quickselect | ISC |
| readdirp | MIT |
| reka-ui | MIT |
| remove-accents | MIT |
| require-from-string | MIT |
| rolldown | MIT |
| scule | MIT |
| source-map-js | BSD-3-Clause |
| striptags | MIT |
| stylis | MIT |
| supercluster | ISC |
| three | MIT |
| throttle-debounce | MIT |
| tinyglobby | MIT |
| tinyqueue | ISC |
| topojson-client | ISC |
| tslib | 0BSD |
| ufo | MIT |
| unplugin | MIT |
| unplugin-utils | MIT |
| uqr | MIT |
| vue | MIT |
| vue-demi | MIT |
| vue-i18n | MIT |
| vue-router | MIT |
| vue-sonner | MIT |
| webpack-virtual-modules | MIT |
| zustand | MIT |

## Simulador de la DIAN

2 paquetes de `simulator/package-lock.json` (`npm ls --omit=dev`): el reemplazo local de la DIAN.

| Paquete | Licencia |
| --- | --- |
| @xmldom/xmldom | MIT |
| fflate | MIT |

## App de CDK

26 paquetes de `infra/cdk/package-lock.json` (`npm ls --omit=dev`): la infraestructura como código de los stacks.

| Paquete | Licencia |
| --- | --- |
| @aws-cdk/asset-awscli-v1 | Apache-2.0 |
| @aws-cdk/asset-node-proxy-agent-v6 | Apache-2.0 |
| @aws-cdk/cloud-assembly-api | Apache-2.0 |
| @aws-cdk/cloud-assembly-schema | Apache-2.0 |
| @aws/cloudformation-validate | Apache-2.0 |
| @balena/dockerignore | Apache-2.0 |
| @isaacs/nonrecursive-glob-match | BlueOak-1.0.0 |
| aws-cdk-lib | Apache-2.0 |
| balanced-match | MIT |
| brace-expansion | MIT |
| case | (MIT OR GPL-3.0-or-later) |
| cdk-nag | Apache-2.0 |
| constructs | Apache-2.0 |
| fs-extra | MIT |
| graceful-fs | BlueOak-1.0.0 |
| ignore | MIT |
| json-source-map | MIT |
| jsonfile | MIT |
| jsonschema | MIT |
| mime-db | MIT |
| mime-types | MIT |
| minimatch | BlueOak-1.0.0 |
| punycode | MIT |
| semver | ISC |
| universalify | MIT |
| yaml | ISC |

## Servicio serverless

145 paquetes de `infra/functions/package-lock.json` (`npm ls --omit=dev`): las funciones en TypeScript, su despliegue y los guiones de la nube emulada.

| Paquete | Licencia |
| --- | --- |
| @apidevtools/json-schema-ref-parser | MIT |
| @aws-sdk/checksums | Apache-2.0 |
| @aws-sdk/client-api-gateway | Apache-2.0 |
| @aws-sdk/client-apigatewayv2 | Apache-2.0 |
| @aws-sdk/client-cloudformation | Apache-2.0 |
| @aws-sdk/client-cloudfront | Apache-2.0 |
| @aws-sdk/client-cloudwatch | Apache-2.0 |
| @aws-sdk/client-cloudwatch-logs | Apache-2.0 |
| @aws-sdk/client-cognito-identity-provider | Apache-2.0 |
| @aws-sdk/client-ecr | Apache-2.0 |
| @aws-sdk/client-eventbridge | Apache-2.0 |
| @aws-sdk/client-iam | Apache-2.0 |
| @aws-sdk/client-lambda | Apache-2.0 |
| @aws-sdk/client-s3 | Apache-2.0 |
| @aws-sdk/client-scheduler | Apache-2.0 |
| @aws-sdk/client-secrets-manager | Apache-2.0 |
| @aws-sdk/client-sns | Apache-2.0 |
| @aws-sdk/client-sqs | Apache-2.0 |
| @aws-sdk/client-ssm | Apache-2.0 |
| @aws-sdk/client-sso-oidc | Apache-2.0 |
| @aws-sdk/client-sts | Apache-2.0 |
| @aws-sdk/core | Apache-2.0 |
| @aws-sdk/credential-provider-cognito-identity | Apache-2.0 |
| @aws-sdk/credential-provider-env | Apache-2.0 |
| @aws-sdk/credential-provider-http | Apache-2.0 |
| @aws-sdk/credential-provider-ini | Apache-2.0 |
| @aws-sdk/credential-provider-login | Apache-2.0 |
| @aws-sdk/credential-provider-node | Apache-2.0 |
| @aws-sdk/credential-provider-process | Apache-2.0 |
| @aws-sdk/credential-provider-sso | Apache-2.0 |
| @aws-sdk/credential-provider-web-identity | Apache-2.0 |
| @aws-sdk/credential-providers | Apache-2.0 |
| @aws-sdk/lib-storage | Apache-2.0 |
| @aws-sdk/middleware-sdk-api-gateway | Apache-2.0 |
| @aws-sdk/middleware-sdk-s3 | Apache-2.0 |
| @aws-sdk/middleware-sdk-sqs | Apache-2.0 |
| @aws-sdk/nested-clients | Apache-2.0 |
| @aws-sdk/signature-v4-multi-region | Apache-2.0 |
| @aws-sdk/token-providers | Apache-2.0 |
| @aws-sdk/types | Apache-2.0 |
| @aws-sdk/xml-builder | Apache-2.0 |
| @aws/lambda-invoke-store | Apache-2.0 |
| @esbuild/linux-x64 | MIT |
| @nodelib/fs.scandir | MIT |
| @nodelib/fs.stat | MIT |
| @nodelib/fs.walk | MIT |
| @smithy/core | Apache-2.0 |
| @smithy/credential-provider-imds | Apache-2.0 |
| @smithy/fetch-http-handler | Apache-2.0 |
| @smithy/middleware-compression | Apache-2.0 |
| @smithy/node-http-handler | Apache-2.0 |
| @smithy/signature-v4 | Apache-2.0 |
| @smithy/types | Apache-2.0 |
| @types/json-schema | MIT |
| agent-base | MIT |
| ajv | MIT |
| ajv-formats | MIT |
| ansi-regex | MIT |
| argparse | PSF-2.0, Python-2.0 |
| base64-js | MIT |
| bowser | MIT |
| braces | MIT |
| buffer | MIT |
| buffer-crc32 | MIT |
| cli-color | ISC |
| cli-sprintf-format | ISC |
| content-disposition | MIT |
| cross-spawn | MIT |
| d | ISC |
| debug | MIT |
| dotenv | BSD-2-Clause |
| dotenv-expand | BSD-2-Clause |
| duration | ISC |
| es5-ext | ISC |
| es6-iterator | MIT |
| es6-symbol | ISC |
| es6-weak-map | ISC |
| esbuild | MIT |
| esniff | ISC |
| event-emitter | ISC |
| events | MIT |
| ext | ISC |
| fast-deep-equal | MIT |
| fast-glob | MIT |
| fast-uri | BSD-3-Clause |
| fastest-levenshtein | MIT |
| fastq | ISC |
| fflate | MIT |
| filename-reserved-regex | MIT |
| filenamify | MIT |
| fill-range | MIT |
| glob-parent | ISC |
| has-flag | MIT |
| https-proxy-agent | MIT |
| ieee754 | BSD-3-Clause |
| inherits | ISC |
| is-extglob | MIT |
| is-glob | MIT |
| is-number | MIT |
| is-promise | MIT |
| isexe | ISC |
| js-yaml | MIT |
| json-cycle | MIT |
| json-schema-traverse | MIT |
| log | ISC |
| log-node | ISC |
| lru-queue | MIT |
| memoizee | ISC |
| merge2 | MIT |
| micromatch | MIT |
| mime-db | MIT |
| mime-types | MIT |
| ms | MIT |
| next-tick | ISC |
| osls | MIT |
| path-key | MIT |
| pend | MIT |
| picomatch | MIT |
| proxy-agent-negotiate | MIT |
| queue-microtask | MIT |
| readable-stream | MIT |
| require-from-string | MIT |
| reusify | MIT |
| run-parallel | MIT |
| safe-buffer | MIT |
| semver | ISC |
| shebang-command | MIT |
| shebang-regex | MIT |
| signal-exit | ISC |
| sprintf-kit | ISC |
| stream-browserify | MIT |
| string_decoder | MIT |
| supports-color | MIT |
| timers-ext | ISC |
| to-regex-range | MIT |
| tslib | 0BSD |
| tsx | MIT |
| type | ISC |
| undici | MIT |
| uni-global | ISC |
| util-deprecate | MIT |
| which | ISC |
| write-file-atomic | ISC |
| yauzl | MIT |
| yazl | MIT |
