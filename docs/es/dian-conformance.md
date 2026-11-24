# Conformidad con la DIAN

[English](../dian-conformance.md) · **Español**

<!-- Traducción de docs/dian-conformance.md con SHA-256 cc8fc672bf5e8221a4c212419974cb2926455f64483f941848e1e693fca48275. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->

Qué partes del estándar colombiano de facturación electrónica implementa este proyecto, y cómo revisar cada una: el comando, la prueba o el archivo que la muestra. Las rutas son relativas a `backend/`, salvo las que empiezan en la raíz del repositorio.

> **Sin relación con la DIAN.** Aquí, cada documento lo valida el simulador local de `simulator/`, nunca la DIAN. La habilitación nunca se hizo, los certificados son sintéticos, y la DIAN nunca aceptó ningún CUFE, CUDE ni firma producidos aquí ([limitations.md](limitations.md#relación-con-la-dian)).

## El estándar

El proyecto sigue la versión 1.9 del *Anexo técnico de la Factura Electrónica de Venta*, que la DIAN adoptó con la Resolución 000165 del 1 de noviembre de 2023. El anexo, sus tablas, sus esquemas y sus ejemplos vienen en la caja de herramientas de la DIAN, *Caja de herramientas FE - V1.9 - (v2026-2)*. El código cita el anexo por sus secciones y tablas ("annex 1.9, table 13.2.4"), y los documentos son UBL 2.1 (OASIS) con una firma XAdES-EPES.

El repositorio guarda solo los esquemas con los que valida, sin cambios, en `backend/resources/dian/xsd` ([NOTICE.es.txt](../../NOTICE.es.txt)). `make dian-toolbox` descarga la caja de herramientas desde la DIAN, revisa su SHA-256 y compara esos 19 esquemas, byte por byte, con los de la caja. Las [fuentes](#fuentes) están al final.

## Documentos

| Documento | Código de tipo | CustomizationID | ProfileID | Código único |
| --- | --- | --- | --- | --- |
| Factura electrónica de venta | InvoiceTypeCode `01` | `10`, operación estándar | `DIAN 2.1: Factura Electrónica de Venta` | CUFE-SHA384 |
| Nota crédito | CreditNoteTypeCode `91` | `20`, una nota que referencia una factura | `DIAN 2.1: Nota Crédito de Factura Electrónica de Venta` | CUDE-SHA384 |
| Nota débito | Ninguno en el esquema de DebitNote | `30`, una nota que referencia una factura | `DIAN 2.1: Nota Débito de Factura Electrónica de Venta` | CUDE-SHA384 |

`ProfileExecutionID` y el `schemeID` del UUID son siempre `2`, el ambiente de habilitación: producción (`1`) no tiene ningún caso en el código (`app/Modules/Dian/Enums/DianEnvironment.php`). Una nota siempre referencia una factura aceptada, con uno de los conceptos oficiales de las tablas 13.2.4 (notas crédito, 1 a 6) y 13.2.5 (notas débito, 1 a 4), cuyos nombres se conservan como los escribe el anexo (`app/Modules/Reference/Enums/CreditNoteConcept.php`, `DebitNoteConcept.php`).

**Lo muestran** `make dian-check` y `tests/Integration/Dian/ConformanceTest.php`, que construyen, firman y validan los tres documentos, y las pruebas del simulador (`make test-simulator`).

## Estructura UBL 2.1

`app/Modules/Dian/Ubl/UblDocumentXml.php` escribe cada documento solo a partir de sus copias congeladas ([architecture.md](architecture.md#modelo-de-datos)), y no recalcula nada. Sus grupos, en orden:

- `UBLExtensions`: las extensiones de la DIAN (`InvoiceControl` en las facturas, con la resolución de numeración y su rango, `InvoiceSource`, `SoftwareProvider`, `SoftwareSecurityCode`, `AuthorizationProvider` con el NIT de la DIAN, y `QRCode`), y luego una extensión vacía para la firma.
- El encabezado: versión, personalización y perfil, número, UUID (el CUFE o el CUDE), fechas, tipo, observaciones, moneda y cantidad de líneas.
- En las notas, `DiscrepancyResponse` y `BillingReference`, con el número, el CUFE y la fecha de la factura.
- `AccountingSupplierParty` y `AccountingCustomerParty`, con sus tributos, sus responsabilidades fiscales y sus direcciones por código DANE.
- `PaymentMeans`, `TaxTotal` por impuesto y tarifa, `WithholdingTaxTotal` en las facturas, y `LegalMonetaryTotal` (`RequestedMonetaryTotal` en las notas débito).
- Las líneas, con su descuento, su impuesto, el ítem y su código estándar (UNSPSC, GTIN o `999`), y el precio.

`PayableRoundingAmount`, `AllowanceTotalAmount`, `ChargeTotalAmount` y `PrepaidPayment` nunca se escriben.

**Lo muestra** `app/Modules/Dian/Ubl/XsdValidator.php`, que valida cada documento contra `UBL-Invoice-2.1.xsd`, `UBL-CreditNote-2.1.xsd` o `UBL-DebitNote-2.1.xsd`: `make dian-check` (pasos 2, 5 y 7), `ConformanceTest` y la regla ZB01 del simulador, con `xmllint`. Los esquemas procesan `sts:DianExtensions` de forma laxa, así que no revisan las extensiones de la DIAN: el simulador revisa su software, su numeración y su código de seguridad ([más abajo](#los-servicios-web-que-imita-el-simulador)).

## CUFE y CUDE

El CUFE es el hash SHA-384, en hexadecimal en minúsculas, de estos valores concatenados sin separadores: el número, la fecha y la hora de emisión, el valor antes de impuestos, `01` y el IVA, `04` y el INC, `03` y el ICA, el total, el NIT del emisor, la identificación del adquiriente, la clave técnica del rango de numeración y el ambiente (`app/Modules/Dian/Domain/Cufe.php`). Los valores se escriben con dos decimales, truncados, no redondeados. El CUDE de una nota se calcula igual, con el PIN del software en lugar de la clave técnica (`Cude.php`). Solo el IVA y el INC se modelan como impuestos, así que el valor del ICA es siempre `0.00`.

**Lo muestran** los ejemplos del anexo, reproducidos hasta el último carácter:

| Ejemplo | Código esperado | Dónde |
| --- | --- | --- |
| CUFE de una factura, sección 11.2.1 | `8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4` | `tests/Unit/Dian/DocumentKeyTest.php::test_the_cufe_of_the_annex_example_11_2_1`; `make dian-check`, paso 1 |
| CUDE de una nota crédito, sección 11.4.3 | `907e4444decc9e59c160a2fb3b6659b33dc5b632a5008922b9a62f83f757b1c448e47f5867f2b50dbdb96f48c7681168` | `DocumentKeyTest::test_the_cude_of_the_credit_note_example_11_4_3`; `make dian-check`, paso 6 |

El ejemplo de la nota débito, sección 11.4.5, no se puede reproducir con sus propios datos. Su composición impresa, `ND10012019-01-1810:58:00-05:0030000.00010.00042400.00030.0032400.0090019726410254102102012`, da `3fa73a86d57d9341c536afde1f85c4efd9d4591c2c22bce4dfb0e6b0d2e83b8f047a8bde7098292e9d2493e60d1c31da`, mientras que el anexo imprime `b9483dc2a17167feedf37b6bd67c4204e7b601933e0e389cffbd545e4d0ec370b403cbb41ff656776cb6cb5d8348ecd4`. `app/Modules/Dian/Console/DianCheckCommand.php` lo dice, y ninguna prueba lo usa.

El simulador recalcula cada CUFE y cada CUDE con la clave técnica del rango o con el PIN del software que tiene registrados (reglas FAD06, CAD06 y DAD06). `DocumentKeyTest` también revisa el formato de los valores, y que cambiar cualquier valor de la composición cambia el código.

## Código de seguridad del software

`SoftwareSecurityCode` es el SHA-384 del ID del software, su PIN y el número del documento, como lo define la sección 11.8 (`app/Modules/Dian/Domain/SoftwareSecurityCode.php`).

**Lo muestran** `SoftwareSecurityCodeTest`, que lo compara con valores que `sha384sum` calculó por fuera del código, y el simulador, que lo recalcula (reglas FAB27b, CAB27b y DAB27b) en `simulator/test/rules.test.ts`.

## Firma XAdES-EPES

`app/Modules/Dian/Signing/XadesSigner.php` firma cada documento, y también el AttachedDocument, con el certificado de la empresa:

- Canonicalización C14N 1.0 sin comentarios, RSA con SHA-256 y resúmenes SHA-256.
- Tres referencias: el documento, con la transformación enveloped signature; el `KeyInfo` y las `SignedProperties`. `KeyInfo` lleva el certificado de firma.
- `SigningTime` en la zona horaria de Colombia, y `SigningCertificate` con el resumen SHA-256, el emisor y el número de serie de cada certificado del archivo subido, primero el de firma.
- `SignaturePolicyIdentifier`: `https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf`, descrita como "Política de firma para facturas electrónicas de la República de Colombia", con el resumen SHA-256 `dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=`. La sección 10.10 del anexo da la URL, pero no el resumen; el resumen es el que traen los ejemplos firmados de la caja de herramientas. El PDF de la política no se descarga ni se guarda aquí.
- `SignerRole`: `supplier` (sección 10.12).

Los certificados son sintéticos y autofirmados, con claves RSA de 2.048 bits, y valen por dos años (`app/Modules/Dian/Services/SigningCertificates.php`). Solo el simulador los acepta.

**Lo muestra** `make dian-check`: xmlsec1 verifica una factura firmada en el momento (paso 3), rechaza una copia con un dígito de su total cambiado (paso 4), y verifica las notas crédito y débito guardadas (paso 7) y el AttachedDocument de una factura entregada (paso 8). `ConformanceTest` hace lo mismo con los tres documentos, y el simulador verifica cada firma con xmlsec1 (reglas ZD01, ZE02 y ZD02). `ChainTest::test_a_certificate_that_expires_between_issue_and_signing_stops_the_chain_before_anything_is_sent` cubre un certificado que vence en el camino.

## Redondeo

- **Sección 5.2.1, redondeo de la mitad al par.** `app/Modules/Billing/Domain/DocumentCalculator.php` redondea la mitad al par, a dos decimales, como lo pide el anexo citando la NTC 3711: la base de cada línea, su descuento y su impuesto. Cada total es la suma exacta de sus líneas, así que siempre cuadra.
- **Sección 5.2.1.1, una tolerancia de ±2,00** entre un total y sus líneas. Aquí los totales son sumas exactas, así que no necesitan tolerancia.
- **Sección 5.2.1.2, el IVA redondeado al múltiplo de 10 pesos más cercano.** Es opcional, y no se aplica.
- **El CUFE y el CUDE** toman sus valores truncados a dos decimales ([más arriba](#cufe-y-cude)).
- **Tarifas por mil.** El ICA y el ReteICA se ingresan como porcentajes y se escriben por mil (9,66 por mil es 0,966 %).

**Lo muestra** `tests/Unit/Billing/DocumentCalculatorTest.php`: `test_the_line_base_rounds_half_to_even`, `test_taxes_and_discounts_round_half_to_even_too`, `test_reteica_per_thousand_rounds_half_to_even` y `test_the_identities_of_the_totals_always_hold`, sobre 300 documentos aleatorios. El simulador revisa cada total contra sus líneas (reglas FAU02, FAU04, FAU06 y FAU14, y sus equivalentes en las notas), y redondea los dos lados a pesos enteros.

## AttachedDocument

Cuando la DIAN acepta un documento, `app/Modules/Dian/Ubl/AttachedDocumentXml.php` arma el contenedor de la sección 6.4 (campos AE01 a AE55): `CustomizationID` "Documentos adjuntos", el remitente y el destinatario, el documento firmado en `Attachment`, y el ApplicationResponse de la DIAN, con el UUID y el código de respuesta, en `ParentDocumentLineReference`. Se firma con el mismo firmador y se guarda como `ad{NIT}{ppp}{aa}{dddddddd}.xml` (`app/Modules/Delivery/Services/AttachedDocuments.php`). La caja de herramientas no trae ningún ejemplo, así que sigue la tabla del anexo. Su `ValidatorID` nombra al simulador local en lugar del literal de AE52, y sus demás literales son los de una factura, también en las notas ([limitations.md](limitations.md#entrega-y-pdf)).

**Lo muestra** `make delivery-check`: valida el AttachedDocument contra `UBL-AttachedDocument-2.1.xsd`, y revisa que tenga una sola firma y que el documento y el ApplicationResponse que lleva adentro coincidan byte por byte con los guardados. También lee el PDF con poppler, y encuentra el correo con su zip en el buzón local. `make dian-check` (paso 8) verifica esa firma con xmlsec1, confiando solo en el certificado de la empresa, y rechaza una copia con un dígito cambiado en la factura que lleva adentro: la firma cubre los documentos que contiene. `tests/Integration/Delivery/DeliveryTest.php::test_an_accepted_invoice_is_delivered_with_its_attached_document_pdf_and_email` cubre la entrega.

## Código QR

`sts:QRCode` guarda la dirección que la DIAN da para un documento: `https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=` y su CUFE o CUDE, en el catálogo de habilitación (`app/Modules/Dian/Enums/DianEnvironment.php`). Al pie de cada página, el PDF dibuja un código QR con el número, la fecha y la hora de emisión, el NIT del emisor, la identificación del adquiriente, el valor, el IVA, los demás impuestos, el total, el código único y esa dirección (`app/Modules/Delivery/Support/QrContent.php`). La librería bacon/bacon-qr-code lo codifica con corrección de errores M (`app/Modules/Delivery/Pdf/QrSvg.php`). Las notas usan las mismas etiquetas, un supuesto que el código deja escrito.

**Lo muestra** `tests/Integration/Delivery/QrContentTest.php`, que emite una factura con IVA e INC y revisa cada línea del texto que codifica el código QR (la dirección de verificación va de última) contra el documento y su `sts:QRCode`. Lee el texto antes de que QrSvg lo dibuje: ninguna prueba vuelve a leer la imagen, porque eso pediría un decodificador de QR, una dependencia que el proyecto no agrega.

## Los servicios web que imita el simulador

El simulador (`simulator/`, Node.js y TypeScript, puerto 8602) hace las veces del servicio web SOAP 1.2 de la DIAN, `WcfDianCustomerServices.svc`, con dos operaciones:

- **SendBillSync** recibe el zip de un documento firmado, lo valida y devuelve un ApplicationResponse: estado `00` cuando lo acepta, y `99` con cada regla que incumplió cuando lo rechaza.
- **GetStatus** devuelve el estado de un track id, o `90`, "TrackId no encontrado", cuando no lo conoce.

El backend llega a él por `app/Modules/Dian/Transmission/SoapDianGateway.php`, detrás de la interfaz `DianGateway`, con WS-Addressing y sin WS-Security. Su ApplicationResponse no va firmado, lleva un `LineResponse` por hallazgo y tiene un UUID que no sigue la fórmula de la sección 11.5. Un modo de falla por empresa lo hace rechazar, no dar respuesta, responder con HTTP 500, responder despacio o fallar al azar ([README](../../README.es.md#un-recorrido-de-cinco-minutos)).

Las reglas que aplica, de las secciones 8 y 12 del anexo, con sus códigos y con sus mensajes en español, tal como los escribe el anexo (`simulator/src/rules.ts`). FA es para las facturas, CA y CB para las notas crédito, DA y DB para las notas débito; cada regla rechaza el documento, salvo las dos marcadas como notificación.

| Reglas | Qué revisa el simulador |
| --- | --- |
| ZB01 | El XML está bien formado y es válido contra el esquema de su tipo |
| FAB24b, CAB24b, DAB24b | El NIT del emisor está registrado y el ID del software corresponde a un software suyo |
| FAB27b, CAB27b, DAB27b | El código de seguridad del software |
| FAB05b, FAB07b, FAB08b, FAB10b, FAB11b, FAB12b | La resolución de numeración es del emisor, con sus fechas, su prefijo y su rango |
| FAD05b, FAD05c | El número está dentro del rango |
| FAD06, CAD06, DAD06 | El CUFE o el CUDE, recalculado |
| FAD09a, FAD09b | La fecha de emisión está dentro de la vigencia del rango |
| FAD09e, CAD09e, DAD09e | La fecha de emisión es la fecha de la firma en Colombia |
| FAU02, FAU04, FAU06, FAU14 y las de las notas | Los totales cuadran |
| CBG01, CBG03, CBG04, CBG05, DBG01, DBG03, DBG04 | Una nota referencia una factura aceptada del mismo emisor, por su número y su CUFE |
| CBG06 (notificación), DBG06 | La factura referenciada no tiene una fecha posterior a la de la nota |
| ZD01, ZE02, ZD02 | Una firma con su certificado, que xmlsec1 verifica, vigente cuando se firmó |
| ZD05 (notificación) | El certificado es autofirmado: un rechazo en la DIAN, una notificación aquí para que funcionen los certificados de prueba |
| 90 | El número ya se aceptó para ese emisor y ese tipo |

También revisa los nombres de los archivos zip y XML (secciones 6.5.7 y 6.5.8), el tamaño y las entradas del zip, y la versión de UBL. No revisa `CustomizationID`, `ProfileID`, los códigos de tipo, el código QR, el concepto de la nota ni una cabecera WS-Security.

**Lo muestra** `make test-simulator`: `simulator/test/rules.test.ts` tiene un caso para cada regla salvo la 90, y `simulator/test/server.test.ts` cubre la regla 90 y los modos de falla por HTTP.

## Cómo revisarlo

| Comando | Qué muestra |
| --- | --- |
| `make dian-check` | El CUFE de 11.2.1 y el CUDE de 11.4.3; el XML de una factura emitida contra el XSD; su firma verificada por xmlsec1 y una copia alterada rechazada; las notas crédito y débito guardadas, válidas y verificadas; el AttachedDocument de una factura entregada, su firma verificada y una copia alterada rechazada |
| `make delivery-check` | El AttachedDocument contra su XSD, con el documento y la respuesta que lleva adentro; el PDF leído con poppler; el correo en el buzón local |
| `make test-backend` | `ConformanceTest`, `DocumentKeyTest`, `SoftwareSecurityCodeTest`, `DocumentCalculatorTest`, `ChainTest`, `DeliveryTest` y `QrContentTest`, entre las demás |
| `make test-simulator` | Cada regla del simulador, cada una con un documento que la incumple |
| `make dian-toolbox` | La caja de herramientas, descargada con su SHA-256, y los esquemas del repositorio comparados con los suyos |
| `make dian-schematron` | Opcional: el schematron de 2019 de la caja de herramientas sobre la factura, la nota crédito y la nota débito de la demo. Sus 24 hallazgos vienen de reglas anteriores al anexo 1.9, cada uno listado con su razón en el Makefile, y cualquier otro hallazgo hace fallar el comando |

## Lo que no está implementado

- **La habilitación.** El set de pruebas nunca se envía (no hay SendTestSetAsync), y el `test_set_id` de la configuración solo se guarda. La DIAN nunca validó nada.
- **La firma WS-Security de la cabecera SOAP**, que exige el servicio web real (sección 7.5).
- **Certificados reales.** Sin certificados de una entidad acreditada por la ONAC, sin revisión de la cadena y sin revisión de revocación (CRL u OCSP).
- **Los eventos de RADIAN**: los eventos de recibo, de aceptación y de reclamo del ApplicationResponse (códigos 030 a 034).
- **El documento equivalente POS**, el **documento soporte** y la **nómina electrónica**.
- **Otras facturas y notas**: exportación (`02`), contingencia (`03` y `04`) y notas sin una factura referenciada.
- **El ambiente de producción** (`ProfileExecutionID` 1) y su catálogo, y un proveedor tecnológico: el software es propio del emisor.

[limitations.md](limitations.md#relación-con-la-dian) tiene el resto, como lo que no se pudo revisar sin el servicio real.

## Fuentes

Los documentos oficiales con los que se construyó este proyecto. El repositorio solo redistribuye los esquemas, sin cambios ([NOTICE.es.txt](../../NOTICE.es.txt)).

| Documento | Versión | Fecha | Dónde |
| --- | --- | --- | --- |
| Anexo técnico de la Factura Electrónica de Venta (DIAN) | 1.9, adoptado por la Resolución 000165 | 1 de noviembre de 2023 | En la caja de herramientas, `Anexo técnico/` |
| Caja de herramientas FE (DIAN): anexo, tablas referenciadas, esquemas, ejemplos, schematron | V1.9 (v2026-2) | Descargada el 17 de noviembre de 2026; SHA-256 del zip `868bc7ac184f646b47a61e2b3a978c0c195fc958e8ec7b54e95010717aa477e5` | https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip, con `make dian-toolbox` |
| El schematron de la caja de herramientas, `XSL/DIAN-UBL21-model-compiled.xsl` y `Schemes/listacodigos` | listacodigos v1.6 | 2019 | En la caja de herramientas, para `make dian-schematron` |
| Política de firma para facturas electrónicas de la República de Colombia (DIAN) | v2 | No se consultó: solo se usan su URL y su resumen | https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf |
| Universal Business Language (OASIS) | 2.1 OS, y CS1 para tres esquemas | 4 de noviembre de 2013 (OS), 16 de junio de 2013 (CS1) | https://docs.oasis-open.org/ubl/os-UBL-2.1/ |
| XML Advanced Electronic Signatures, XAdES (ETSI) | 1.3.2 y 1.4.1 | Como OASIS las copió en UBL 2.1 | http://uri.etsi.org/01903/ |
| DIVIPOLA, los códigos de los municipios (DANE) | El conjunto de datos gdxc-w37w | Actualizado el 24 de enero de 2025, descargado el 18 de noviembre de 2026 | https://www.datos.gov.co/resource/gdxc-w37w.json |

*Sin relación con la DIAN.*
