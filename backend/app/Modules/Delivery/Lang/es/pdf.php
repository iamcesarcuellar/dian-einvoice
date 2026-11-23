<?php

// The graphic representation of the invoice and its notes (PDF) in Spanish. Proper terms of the DIAN (NIT, CUFE,
// CUDE, IVA, DIAN) stay as they are.

return [

    'titles' => [
        'invoice' => 'Factura electrónica de venta',
        'credit_note' => 'Nota crédito',
        'debit_note' => 'Nota débito',
    ],
    'test_mark' => 'Documento de prueba',
    'test_notice' => 'Ambiente de habilitación (pruebas) de la DIAN · validado por un simulador local, no por la DIAN · sin validez tributaria',
    'watermark' => 'DOCUMENTO DE PRUEBA',

    'nit_value' => 'NIT :nit',
    'regime' => 'Tributo: :scheme · Responsabilidades fiscales: :responsibilities',

    'bill_to' => 'Adquiriente',
    'invoice_details' => 'Datos de la factura',
    'issued' => 'Fecha de emisión',
    'issued_value' => ':date, :time (UTC:offset)',
    'due_date' => 'Fecha de vencimiento',
    'no_due_date' => 'Ninguna (contado)',
    'payment_form' => 'Forma de pago',
    'payment_means' => 'Medio de pago',

    'columns' => [
        'position' => '#',
        'code' => 'Código',
        'description' => 'Descripción',
        'quantity' => 'Cant.',
        'unit' => 'Unidad',
        'unit_price' => 'Precio unitario',
        'discount' => 'Desc.',
        'tax' => 'Impuesto',
        'total' => 'Total',
    ],
    'excluded' => 'Excluido',

    'subtotal' => 'Subtotal',
    'discounts' => 'Descuentos',
    'on' => 'sobre :base',
    'rate_on' => ':rate sobre :base',
    'total' => 'Total',
    'net' => 'Neto a recibir',
    'in_words' => 'Valor en letras',
    'words' => ':words pesos colombianos',
    'words_cents' => ':words pesos colombianos con :cents/100',
    'notes' => 'Observaciones',

    'reference' => [
        'title' => 'Factura corregida',
        'invoice' => 'Factura electrónica de venta :number del :date',
        'cufe' => 'CUFE de la factura',
        'concept' => 'Concepto :code · :name',
    ],

    'validation' => 'Validada el :date a las :time por el simulador local de la DIAN de este proyecto, una herramienta de desarrollo. La DIAN no validó este documento.',
    'authorization' => 'Numeración autorizada por la resolución DIAN :number del :date: prefijo :prefix, del :from al :to, vigente del :valid_from al :valid_to.',
    'authorization_no_prefix' => 'Numeración autorizada por la resolución DIAN :number del :date: del :from al :to, vigente del :valid_from al :valid_to.',
    'note_numbering' => 'Numeración propia del emisor para este tipo de nota, sin resolución DIAN: prefijo :prefix, del :from al :to.',
    'representations' => [
        'invoice' => 'Representación gráfica de la factura electrónica de venta.',
        'credit_note' => 'Representación gráfica de una nota crédito a una factura electrónica de venta.',
        'debit_note' => 'Representación gráfica de una nota débito a una factura electrónica de venta.',
    ],
    'software' => 'Software: software propio del emisor, :name, NIT :nit.',
    'page' => 'Página :page de :pages',
    'qr_label' => 'Código QR de verificación',

    'metadata_title' => ':title :number (documento de prueba)',
    'metadata_subject' => 'Representación gráfica de una :title. Un documento de prueba validado por un simulador local, sin validez tributaria.',

];
