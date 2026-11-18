{{-- Styles of the PDF (CSS 2.1, as dompdf reads it). One family (Inter: regular, 500 for labels, bold for its semibold face; its digits are the tabular figures), one accent (the indigo of the web app), thin rules. --}}
/* dompdf starts the html element from the @page style, so html must keep these margins: the running
   header and footer sit in them. Only body is reset. */
@@page {
    margin: 27mm 20mm 52mm 20mm;
}

body {
    margin: 0;
    padding: 0;
}

body {
    font-family: Inter;
    font-size: 8.4pt;
    line-height: 1.45;
    color: #18181b;
}

table {
    border-collapse: collapse;
    width: 100%;
}

td, th {
    vertical-align: top;
    padding: 0;
}

.label {
    font-weight: 500;
    font-size: 6.4pt;
    line-height: 1.3;
    letter-spacing: 0.7pt;
    text-transform: uppercase;
    color: #71717a;
}

.muted {
    color: #71717a;
}

/* Running header, on every page: the test mark and the document */
.running-header {
    position: fixed;
    top: -18mm;
    left: 0;
    right: 0;
}

td.band {
    background-color: #f4f4f5;
    font-size: 6.6pt;
    line-height: 1.4;
    color: #52525b;
    padding: 2.2mm 3mm;
}

td.band-ref {
    background-color: #f4f4f5;
    font-size: 6.6pt;
    line-height: 1.4;
    color: #18181b;
    padding: 2.2mm 3mm;
    text-align: right;
    white-space: nowrap;
}

span.band-mark {
    font-weight: bold;
    color: #4f46e5;
    letter-spacing: 0.8pt;
    text-transform: uppercase;
}

/* Running footer, on every page: QR code, CUFE, validation, numbering, software */
.running-footer {
    position: fixed;
    bottom: -40mm;
    left: 0;
    right: 0;
    height: 34mm;
}

td.foot-qr {
    width: 29mm;
    padding-top: 3mm;
    border-top: 0.15mm solid #e4e4e7;
}

td.foot-text {
    padding-top: 3mm;
    border-top: 0.15mm solid #e4e4e7;
    font-size: 6.4pt;
    line-height: 1.45;
    color: #52525b;
}

div.cufe {
    font-size: 6.2pt;
    color: #18181b;
    padding-top: 0.6mm;
    padding-bottom: 1.4mm;
}

div.validation {
    color: #4f46e5;
}

/* Masthead: the issuer and the document */
td.masthead {
    padding-bottom: 6mm;
    border-bottom: 0.25mm solid #18181b;
}

div.issuer-name {
    font-size: 15pt;
    line-height: 1.2;
    font-weight: bold;
    padding-bottom: 1.6mm;
}

div.issuer-line {
    font-size: 8pt;
    color: #52525b;
}

div.regime {
    font-size: 7pt;
    color: #71717a;
    padding-top: 1.4mm;
}

div.doc-label {
    font-weight: 500;
    font-size: 7pt;
    letter-spacing: 1.2pt;
    text-transform: uppercase;
    color: #4f46e5;
    text-align: right;
}

div.doc-number {
    font-size: 19pt;
    line-height: 1.15;
    font-weight: bold;
    text-align: right;
    padding-top: 1.2mm;
}

div.doc-test {
    font-size: 7pt;
    color: #71717a;
    text-align: right;
    padding-top: 1.2mm;
}

/* Customer, dates and payment */
td.party {
    padding-top: 6mm;
    padding-bottom: 8mm;
    padding-right: 8mm;
}

td.facts {
    padding-top: 6mm;
    padding-bottom: 8mm;
}

div.party-name {
    font-size: 9.6pt;
    font-weight: bold;
    padding-top: 1.6mm;
    padding-bottom: 0.4mm;
}

td.fact-label {
    padding-top: 0.3mm;
    padding-bottom: 1.6mm;
}

td.fact-value {
    padding-bottom: 1.6mm;
    text-align: right;
}

/* Lines; the header row repeats on every page the table runs over */
th.col, th.col-r {
    font-weight: 500;
    font-size: 6.4pt;
    letter-spacing: 0.6pt;
    text-transform: uppercase;
    color: #71717a;
    text-align: left;
    padding: 0 1.6mm 2mm 1.6mm;
    border-bottom: 0.25mm solid #18181b;
}

th.col-r {
    text-align: right;
}

tr.line {
    page-break-inside: avoid;
}

td.cell, td.cell-r, td.cell-muted {
    font-size: 7.8pt;
    padding: 2.3mm 1.6mm;
    border-bottom: 0.15mm solid #e4e4e7;
}

td.cell-r {
    text-align: right;
    white-space: nowrap;
}

td.cell-muted {
    color: #71717a;
}

/* Totals, amount in words and notes, kept together on the last page */
div.summary {
    page-break-inside: avoid;
    padding-top: 7mm;
}

td.words {
    padding-right: 10mm;
}

div.words-text {
    padding-top: 1.6mm;
    color: #3f3f46;
}

td.t-label {
    padding: 1.3mm 0;
    color: #52525b;
}

td.t-value {
    padding: 1.3mm 0;
    text-align: right;
    white-space: nowrap;
}

td.t-label-total, td.t-value-total {
    padding: 2.4mm 0 1.6mm 0;
    border-top: 0.25mm solid #18181b;
    font-size: 9.6pt;
    font-weight: bold;
}

td.t-label-net, td.t-value-net {
    padding: 2.4mm 0 0 0;
    border-top: 0.15mm solid #e4e4e7;
    font-size: 10.4pt;
    font-weight: bold;
    color: #4f46e5;
}

td.t-value-total, td.t-value-net {
    text-align: right;
    white-space: nowrap;
}

span.t-detail {
    font-size: 6.8pt;
    color: #a1a1aa;
}

div.notes {
    padding-top: 9mm;
}

/* A note: the invoice it corrects */
div.reference {
    margin-bottom: 7mm;
    padding: 3mm 3.5mm 2mm 3.5mm;
    border: 0.3mm solid #e4e4e7;
    border-radius: 1.5mm;
}

td.reference-main {
    padding-top: 1.4mm;
}

td.reference-concept {
    padding-top: 1.4mm;
    text-align: right;
}

div.reference-label {
    padding-top: 2mm;
    font-size: 6.4pt;
    color: #71717a;
}

div.reference-text {
    padding-top: 0.6mm;
    color: #3f3f46;
}

div.notes-text {
    padding-top: 1.6mm;
    color: #3f3f46;
}
