<?php

// The details of the error responses of invoices, credit and debit notes and their numbering, in Spanish.

return [

    'issued' => [
        'invoice' => 'Esta factura ya está emitida.',
        'credit_note' => 'Esta nota crédito ya está emitida.',
        'debit_note' => 'Esta nota débito ya está emitida.',
    ],
    'issued_edit' => [
        'invoice' => 'Las facturas emitidas no se pueden editar.',
        'credit_note' => 'Las notas crédito emitidas no se pueden editar.',
        'debit_note' => 'Las notas débito emitidas no se pueden editar.',
    ],
    'issued_delete' => [
        'invoice' => 'Las facturas emitidas no se pueden eliminar.',
        'credit_note' => 'Las notas crédito emitidas no se pueden eliminar.',
        'debit_note' => 'Las notas débito emitidas no se pueden eliminar.',
    ],

    'zero_total' => 'El total debe ser mayor que cero.',
    'choose_customer' => 'Elige el cliente.',
    'inactive_customer' => 'El cliente está inactivo. Actívalo o elige otro.',
    'no_lines' => 'Agrega al menos una línea.',
    'describe_line' => 'Describe la línea.',
    'quantity' => 'La cantidad debe ser mayor que cero.',
    'unit_price' => 'El precio unitario no puede ser negativo.',
    'payment_form' => 'Elige la forma de pago.',
    'payment_means' => 'Elige el medio de pago.',
    'due_date_required' => 'Una factura a crédito necesita fecha de vencimiento.',
    'due_date_past' => 'La fecha de vencimiento no puede ser anterior a hoy.',

    'invoice_not_accepted' => 'El simulador local aún no ha aceptado la factura :number, así que ninguna nota puede corregirla todavía.',
    'invoice_voided' => 'La factura :number está anulada, así que ninguna otra nota puede corregirla.',
    'nothing_to_credit' => 'No queda nada por acreditar en la factura :number.',
    'choose_concept' => [
        'credit_note' => 'Elige el concepto de la nota crédito.',
        'debit_note' => 'Elige el concepto de la nota débito.',
    ],
    'describe_correction' => 'Describe la corrección en :min a :max caracteres.',
    'choose_invoice' => 'Elige la factura emitida que corrige la nota.',
    'credit_exceeds' => 'La nota crédito es por :amount, más de los :remaining que quedan por acreditar en la factura :number.',
    'void_mismatch' => 'Una nota crédito que anula la factura :number debe ser por todo lo que queda por acreditar, :remaining, o por menos de un peso por debajo; esta es por :amount.',
    'void_reason' => 'Anulación de la factura :number del :date: se acredita todo lo que quedaba por acreditar, :remaining.',

    'rate_inactive' => ':name ya no está activo. Revisa el borrador y elige otro.',
    'rate_changed' => ':name cambió de :from % a :to % desde que se guardó el borrador. Revisa el borrador y guárdalo de nuevo.',

    'numbering' => [
        'choose' => [
            'invoice' => 'Elige la numeración de la factura. Agrega una en Configuración → Numeración.',
            'credit_note' => 'Elige la numeración de la nota crédito. Agrega una en Configuración → Numeración.',
            'debit_note' => 'Elige la numeración de la nota débito. Agrega una en Configuración → Numeración.',
        ],
        'wrong_type' => [
            'invoice' => 'Esta numeración no es para facturas.',
            'credit_note' => 'Esta numeración no es para notas crédito.',
            'debit_note' => 'Esta numeración no es para notas débito.',
        ],
        'range' => ':prefix :from a :to',
        'inactive' => 'La numeración :name está inactiva. Actívala o elige otra.',
        'exhausted' => 'A la numeración :name no le quedan números. Agrega el siguiente rango en Configuración.',
        'expired' => 'La numeración :name venció el :date. Agrega el siguiente rango en Configuración.',
        'upcoming' => 'La numeración :name rige desde el :date.',
        'in_use' => 'Hay documentos que usan esta numeración. Mejor desactívala.',
    ],

    'receipts' => [
        'inactive' => 'La numeración de los recibos de caja está inactiva. Actívala en Configuración → Numeración.',
        'exhausted' => 'A la numeración de los recibos de caja no le quedan números. Agrega el siguiente rango en Configuración → Numeración.',
        'expired' => 'La numeración de los recibos de caja venció. Agrega el siguiente rango en Configuración → Numeración.',
        'upcoming' => 'La numeración de los recibos de caja aún no está vigente.',
        'not_created' => 'No se pudo crear la numeración de los recibos de caja.',
    ],
    'not_payable' => 'Solo reciben pagos las facturas del cliente del pago que el simulador local de la DIAN aceptó.',
    'not_payable_number' => 'Solo reciben pagos las facturas del cliente del pago que el simulador local de la DIAN aceptó; :number no es una de ellas.',

    'no_xml' => 'Los borradores no tienen XML. Primero emite el documento.',
    'xml_pending' => 'El XML firmado de este documento aún no está disponible.',
    'no_response' => 'El simulador local de la DIAN aún no ha respondido por este documento.',

];
