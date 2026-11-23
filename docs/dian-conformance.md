# DIAN conformance

What this project implements of Colombia's electronic invoicing standard, and how to check each point: the command, the test or the file that shows it. Paths are relative to `backend/` unless they start at the repository's root.

> **Not affiliated with DIAN.** Every document here is validated by the local simulator in `simulator/`, never by the DIAN. The habilitación was never done, the certificates are synthetic, and no CUFE, CUDE or signature produced here was ever accepted by the DIAN ([limitations.md](limitations.md#relationship-with-the-dian)).

## The standard

The project follows the technical annex of the electronic sales invoice (*Anexo técnico de la Factura Electrónica de Venta*), version 1.9, which the DIAN adopted with Resolución 000165 of 1 November 2023. The annex, its tables, its schemas and its examples come in the DIAN's toolbox, *Caja de herramientas FE - V1.9 - (v2026-2)*. The code cites the annex by its sections and tables ("annex 1.9, table 13.2.4"), and the documents are UBL 2.1 (OASIS) with an XAdES-EPES signature.

The repository keeps only the schemas it validates with, unchanged, in `backend/resources/dian/xsd` ([NOTICE](../NOTICE)). `make dian-toolbox` downloads the toolbox from the DIAN, checks its SHA-256 and compares those 19 schemas with the toolbox's own, byte for byte. The [sources](#sources) are listed at the end.

## Documents

| Document | Type code | CustomizationID | ProfileID | Key |
| --- | --- | --- | --- | --- |
| Electronic sales invoice | InvoiceTypeCode `01` | `10`, standard operation | `DIAN 2.1: Factura Electrónica de Venta` | CUFE-SHA384 |
| Credit note | CreditNoteTypeCode `91` | `20`, a note that references an invoice | `DIAN 2.1: Nota Crédito de Factura Electrónica de Venta` | CUDE-SHA384 |
| Debit note | None in the DebitNote schema | `30`, a note that references an invoice | `DIAN 2.1: Nota Débito de Factura Electrónica de Venta` | CUDE-SHA384 |

`ProfileExecutionID` and the `schemeID` of the UUID are always `2`, the habilitación environment: production (`1`) has no case in the code (`app/Modules/Dian/Enums/DianEnvironment.php`). A note always references an accepted invoice, with one of the official concepts of tables 13.2.4 (credit notes, 1 to 6) and 13.2.5 (debit notes, 1 to 4), whose names are kept as the annex writes them (`app/Modules/Reference/Enums/CreditNoteConcept.php`, `DebitNoteConcept.php`).

**Shown by** `make dian-check` and `tests/Integration/Dian/ConformanceTest.php`, which build, sign and validate the three documents, and by the simulator's tests (`make test-simulator`).

## UBL 2.1 structure

`app/Modules/Dian/Ubl/UblDocumentXml.php` writes each document from its frozen copies only ([architecture.md](architecture.md#data-model)), and recalculates nothing. Its groups, in order:

- `UBLExtensions`: the DIAN's extensions (`InvoiceControl` for invoices, with the numbering resolution and its range, `InvoiceSource`, `SoftwareProvider`, `SoftwareSecurityCode`, `AuthorizationProvider` with the DIAN's NIT, and `QRCode`), then an empty extension for the signature.
- The header: version, customization and profile, number, UUID (the CUFE or CUDE), dates, type, notes, currency and line count.
- For notes, `DiscrepancyResponse` and `BillingReference`, with the invoice's number, CUFE and date.
- `AccountingSupplierParty` and `AccountingCustomerParty`, with their tax schemes, fiscal responsibilities and addresses by DANE code.
- `PaymentMeans`, `TaxTotal` per tax and rate, `WithholdingTaxTotal` on invoices, and `LegalMonetaryTotal` (`RequestedMonetaryTotal` on debit notes).
- The lines, with their discount, their tax, the item and its standard code (UNSPSC, GTIN, or `999`), and the price.

`PayableRoundingAmount`, `AllowanceTotalAmount`, `ChargeTotalAmount` and `PrepaidPayment` are never written.

**Shown by** `app/Modules/Dian/Ubl/XsdValidator.php`, which validates each document against `UBL-Invoice-2.1.xsd`, `UBL-CreditNote-2.1.xsd` or `UBL-DebitNote-2.1.xsd`: `make dian-check` (steps 2, 5 and 7), `ConformanceTest`, and the simulator's rule ZB01 with `xmllint`. The schemas process `sts:DianExtensions` laxly, so they do not check the DIAN's extensions: the simulator checks their software, numbering and security code ([below](#the-web-services-the-simulator-imitates)).

## CUFE and CUDE

The CUFE is the SHA-384, in lowercase hexadecimal, of the concatenation, without separators, of: the number, the issue date and time, the value before taxes, `01` and the IVA, `04` and the INC, `03` and the ICA, the total, the issuer's NIT, the customer's identification, the technical key of the numbering range, and the environment (`app/Modules/Dian/Domain/Cufe.php`). Amounts are written with two decimals, truncated, not rounded. The CUDE of a note is the same with the software's PIN in place of the technical key (`Cude.php`). Only IVA and INC are modelled as taxes, so the value of ICA is always `0.00`.

**Shown by** the annex's examples, reproduced to the last character:

| Example | Expected key | Where |
| --- | --- | --- |
| CUFE of an invoice, section 11.2.1 | `8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4` | `tests/Unit/Dian/DocumentKeyTest.php::test_the_cufe_of_the_annex_example_11_2_1`; `make dian-check`, step 1 |
| CUDE of a credit note, section 11.4.3 | `907e4444decc9e59c160a2fb3b6659b33dc5b632a5008922b9a62f83f757b1c448e47f5867f2b50dbdb96f48c7681168` | `DocumentKeyTest::test_the_cude_of_the_credit_note_example_11_4_3`; `make dian-check`, step 6 |

The debit note's example, section 11.4.5, cannot be reproduced with its own data. Its printed composition, `ND10012019-01-1810:58:00-05:0030000.00010.00042400.00030.0032400.0090019726410254102102012`, gives `3fa73a86d57d9341c536afde1f85c4efd9d4591c2c22bce4dfb0e6b0d2e83b8f047a8bde7098292e9d2493e60d1c31da`, while the annex prints `b9483dc2a17167feedf37b6bd67c4204e7b601933e0e389cffbd545e4d0ec370b403cbb41ff656776cb6cb5d8348ecd4`. `app/Modules/Dian/Console/DianCheckCommand.php` says so, and no test uses it.

The simulator recalculates every key with the range's technical key or the software's PIN it has registered (rules FAD06, CAD06 and DAD06). `DocumentKeyTest` also checks the format of the amounts and that any value of the composition changes the key.

## Software security code

`SoftwareSecurityCode` is the SHA-384 of the software's id, its PIN and the document's number, as section 11.8 writes it (`app/Modules/Dian/Domain/SoftwareSecurityCode.php`).

**Shown by** the simulator, which recalculates it (rules FAB27b, CAB27b and DAB27b) in `simulator/test/rules.test.ts`. No backend test checks it on its own.

## XAdES-EPES signature

`app/Modules/Dian/Signing/XadesSigner.php` signs every document, and the AttachedDocument, with the company's certificate:

- Canonicalization C14N 1.0 without comments, RSA with SHA-256, and SHA-256 digests.
- Three references: the document, with the enveloped signature transform; the `KeyInfo`; and the `SignedProperties`. `KeyInfo` carries the signing certificate.
- `SigningTime` in Colombia's time zone, and `SigningCertificate` with the SHA-256 digest, the issuer and the serial number of each certificate the uploaded file brings, the signing one first.
- `SignaturePolicyIdentifier`: `https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf`, described as "Política de firma para facturas electrónicas de la República de Colombia", with the SHA-256 digest `dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=`. Section 10.10 of the annex gives the URL but no digest; the digest is the one the toolbox's signed examples carry. The policy's PDF itself is neither downloaded nor kept here.
- `SignerRole`: `supplier` (section 10.12).

The certificates are synthetic: self-signed RSA keys of 2,048 bits, valid two years (`app/Modules/Dian/Services/SigningCertificates.php`). Only the simulator accepts them.

**Shown by** `make dian-check`: xmlsec1 verifies an invoice signed at that moment (step 3), refuses a copy with one digit of its total changed (step 4), and verifies the stored credit and debit notes (step 7). `ConformanceTest` does the same for the three documents, and the simulator verifies every signature with xmlsec1 (rules ZD01, ZE02 and ZD02). `ChainTest::test_a_certificate_that_expires_between_issue_and_signing_stops_the_chain_before_anything_is_sent` covers a certificate that expires on the way. The AttachedDocument's signature is not verified by xmlsec1 in any check.

## Rounding

- **Section 5.2.1, half to even.** `app/Modules/Billing/Domain/DocumentCalculator.php` rounds half to even, to two decimals, as the annex asks, citing NTC 3711: each line's base, its discount and its tax. Each total is the exact sum of its lines, so it always adds up.
- **Section 5.2.1.1, a tolerance of ±2.00** between a total and its lines. The totals here are exact sums, so they need no tolerance.
- **Section 5.2.1.2, IVA rounded to the nearest 10 pesos.** It is optional, and it is not applied.
- **The CUFE and CUDE** take their amounts truncated to two decimals ([above](#cufe-and-cude)).
- **Per-thousand rates.** ICA and ReteICA are entered as percentages and written per thousand (9.66 per thousand is 0.966 %).

**Shown by** `tests/Unit/Billing/DocumentCalculatorTest.php`: `test_the_line_base_rounds_half_to_even`, `test_taxes_and_discounts_round_half_to_even_too`, `test_reteica_per_thousand_rounds_half_to_even`, and `test_the_identities_of_the_totals_always_hold`, over 300 random documents. The simulator checks each total against its lines (rules FAU02, FAU04, FAU06 and FAU14, and those of the notes), rounding both sides to whole pesos.

## AttachedDocument

After the DIAN accepts a document, `app/Modules/Dian/Ubl/AttachedDocumentXml.php` builds the container of section 6.4 (fields AE01 to AE55): `CustomizationID` "Documentos adjuntos", the sender and the receiver, the signed document in `Attachment`, and the DIAN's ApplicationResponse, with the UUID and the response code, in `ParentDocumentLineReference`. It is signed with the same signer and stored as `ad{NIT}{ppp}{aa}{dddddddd}.xml` (`app/Modules/Delivery/Services/AttachedDocuments.php`). The toolbox has no example of it, so it follows the annex's table. Its `ValidatorID` names the local simulator instead of the literal of AE52, and its other literals are an invoice's also for notes ([limitations.md](limitations.md#delivery-and-pdf)).

**Shown by** `make delivery-check`: it validates the AttachedDocument against `UBL-AttachedDocument-2.1.xsd`, checks that the document and the ApplicationResponse inside it are byte for byte the stored ones and that it has one signature, reads the PDF with poppler, and finds the email with its zip in the local mailbox. `tests/Integration/Delivery/DeliveryTest.php::test_an_accepted_invoice_is_delivered_with_its_attached_document_pdf_and_email` covers the delivery.

## QR code

`sts:QRCode` holds the address the DIAN gives for a document: `https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=` and its CUFE or CUDE, the habilitación catalog (`app/Modules/Dian/Enums/DianEnvironment.php`). The PDF draws, at the foot of each page, a QR code with the number, the issue date and time, the issuer's NIT, the customer's identification, the value, the IVA, the other taxes, the total, the key and that address (`app/Modules/Delivery/Support/QrContent.php`), encoded by bacon/bacon-qr-code with error correction M (`app/Modules/Delivery/Pdf/QrSvg.php`). Notes use the same labels, an assumption the code states.

**Shown by** the simulator's rules only indirectly: no test reads the text or the image of the QR code.

## The web services the simulator imitates

The simulator (`simulator/`, Node.js and TypeScript, port 8602) stands in for the DIAN's SOAP 1.2 web service, `WcfDianCustomerServices.svc`, with two operations:

- **SendBillSync** takes the zip of a signed document, validates it and returns an ApplicationResponse: status `00` when it is accepted, and `99` with every rule it broke when it is rejected.
- **GetStatus** returns the status of a track id, or `90`, "TrackId no encontrado", when it does not know it.

The backend reaches it through `app/Modules/Dian/Transmission/SoapDianGateway.php`, behind the `DianGateway` interface, with WS-Addressing and without WS-Security. Its ApplicationResponse is unsigned, carries one `LineResponse` per finding, and has a UUID that is not the formula of section 11.5. A failure mode per company makes it reject, withhold its response, answer HTTP 500, answer slowly or fail at random ([README](../README.md#a-five-minute-tour)).

The rules it applies, from sections 8 and 12 of the annex, with their codes and messages in Spanish as the annex writes them (`simulator/src/rules.ts`). FA is for invoices, CA and CB for credit notes, DA and DB for debit notes; every rule rejects the document, except the two marked as a notification.

| Rules | What the simulator checks |
| --- | --- |
| ZB01 | The XML is well formed and valid against the schema of its type |
| FAB24b, CAB24b, DAB24b | The issuer's NIT is registered and the software id is its software |
| FAB27b, CAB27b, DAB27b | The software security code |
| FAB05b, FAB07b, FAB08b, FAB10b, FAB11b, FAB12b | The numbering resolution is the issuer's, with its dates, prefix and range |
| FAD05b, FAD05c | The number is inside the range |
| FAD06, CAD06, DAD06 | The CUFE or CUDE, recalculated |
| FAD09a, FAD09b | The issue date is inside the range's validity |
| FAD09e, CAD09e, DAD09e | The issue date is the date of the signature in Colombia |
| FAU02, FAU04, FAU06, FAU14 and those of the notes | The totals add up |
| CBG01, CBG03, CBG04, CBG05, DBG01, DBG03, DBG04 | A note references an invoice of the same issuer, accepted, by its number and CUFE |
| CBG06 (notification), DBG06 | The referenced invoice is not dated after the note |
| ZD01, ZE02, ZD02 | A signature with its certificate, which xmlsec1 verifies, valid when it was signed |
| ZD05 (notification) | The certificate is self-signed: a rejection at the DIAN, a notification here so that test certificates can work |
| 90 | The number was already accepted for the issuer and type |

It also checks the names of the zip and of the XML (sections 6.5.7 and 6.5.8), the zip's size and entries, and the UBL version. It does not check `CustomizationID`, `ProfileID`, the type codes, the QR code, the note's concept or a WS-Security header.

**Shown by** `make test-simulator`: `simulator/test/rules.test.ts` has a case for every rule except 90, and `simulator/test/server.test.ts` covers rule 90 and the failure modes over HTTP.

## How to check it

| Command | What it shows |
| --- | --- |
| `make dian-check` | The CUFE of 11.2.1 and the CUDE of 11.4.3; an issued invoice's XML against the XSD; its signature verified by xmlsec1 and a tampered copy refused; the stored credit and debit notes, valid and verified |
| `make delivery-check` | The AttachedDocument against its XSD, with the document and the response inside it; the PDF read with poppler; the email in the local mailbox |
| `make test-backend` | `ConformanceTest`, `DocumentKeyTest`, `DocumentCalculatorTest`, `ChainTest` and `DeliveryTest`, among the rest |
| `make test-simulator` | Every rule of the simulator, with a document that breaks it |
| `make dian-toolbox` | The toolbox, downloaded with its SHA-256, and the repository's schemas compared with its own |
| `make dian-schematron` | Optional: the toolbox's schematron of 2019 over the demo's invoice, credit note and debit note. Its 24 findings come from rules older than annex 1.9, each listed with its reason in the Makefile, and any other finding fails |

## What is not implemented

- **The habilitación.** The test set is never sent (no SendTestSetAsync), and the `test_set_id` of the settings is only stored. Nothing was ever validated by the DIAN.
- **The WS-Security signature of the SOAP header** that the real web service requires (section 7.5).
- **Real certificates.** No certificate of an authority accredited by ONAC, no check of its chain, and no revocation check (CRL or OCSP).
- **RADIAN events**: the receipt, acceptance and claim events of the ApplicationResponse (codes 030 to 034).
- **The POS equivalent document**, the **documento soporte** and the **nómina electrónica**.
- **Other invoices and notes**: export (`02`), contingency (`03` and `04`), and notes without a referenced invoice.
- **The production environment** (`ProfileExecutionID` 1) and its catalog, and a technology provider: the software is the issuer's own.

[limitations.md](limitations.md#relationship-with-the-dian) has the rest, such as what could not be checked without the real service.

## Sources

The official documents this project was built from. The repository redistributes only the schemas, unchanged ([NOTICE](../NOTICE)).

| Document | Version | Date | Where |
| --- | --- | --- | --- |
| Anexo técnico de la Factura Electrónica de Venta (DIAN) | 1.9, adopted by Resolución 000165 | 1 November 2023 | In the toolbox, `Anexo técnico/` |
| Caja de herramientas FE (DIAN): annex, referenced tables, schemas, examples, schematron | V1.9 (v2026-2) | Downloaded on 2026-11-17, zip SHA-256 `868bc7ac184f646b47a61e2b3a978c0c195fc958e8ec7b54e95010717aa477e5` | https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip, through `make dian-toolbox` |
| The toolbox's schematron, `XSL/DIAN-UBL21-model-compiled.xsl` and `Schemes/listacodigos` | listacodigos v1.6 | 2019 | In the toolbox, for `make dian-schematron` |
| Política de firma para facturas electrónicas de la República de Colombia (DIAN) | v2 | Not consulted: only its URL and digest are used | https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf |
| Universal Business Language (OASIS) | 2.1 OS, and CS1 for three schemas | 4 November 2013 (OS), 16 June 2013 (CS1) | https://docs.oasis-open.org/ubl/os-UBL-2.1/ |
| XML Advanced Electronic Signatures, XAdES (ETSI) | 1.3.2 and 1.4.1 | As OASIS copied them into UBL 2.1 | http://uri.etsi.org/01903/ |
| DIVIPOLA, the municipality codes (DANE) | The dataset gdxc-w37w | Updated on 2025-01-24, downloaded on 2026-11-18 | https://www.datos.gov.co/resource/gdxc-w37w.json |

*Not affiliated with DIAN.*
