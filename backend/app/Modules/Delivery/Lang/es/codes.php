<?php

// The Spanish names of the DIAN codes shown on the PDF: the official names of the annex's code lists, the same as
// the reference labels of the web app.

return [

    // Concepts of the notes (tables 13.2.4 and 13.2.5), as the reference labels of the web app.
    'credit_note_concepts' => [
        '1' => 'Devolución parcial de los bienes y/o no aceptación parcial del servicio',
        '2' => 'Anulación de factura electrónica',
        '3' => 'Rebaja o descuento parcial o total',
        '4' => 'Ajuste de precio',
        '5' => 'Descuento comercial por pronto pago',
        '6' => 'Descuento comercial por volumen de ventas',
    ],

    'debit_note_concepts' => [
        '1' => 'Intereses',
        '2' => 'Gastos por cobrar',
        '3' => 'Cambio del valor',
        '4' => 'Otros',
    ],

    'payment_forms' => [
        '1' => 'Contado',
        '2' => 'Crédito',
    ],

    'payment_means' => [
        '10' => 'Efectivo',
        '20' => 'Cheque',
        '42' => 'Consignación bancaria',
        '47' => 'Transferencia débito bancaria',
        '48' => 'Tarjeta crédito',
        '49' => 'Tarjeta débito',
        'ZZZ' => 'Otro',
    ],

    'identification_short' => [
        '10' => 'CNV',
        '11' => 'RC',
        '12' => 'TI',
        '13' => 'CC',
        '21' => 'TE',
        '22' => 'CE',
        '31' => 'NIT',
        '41' => 'PA',
        '42' => 'DIE',
        '47' => 'PEP',
        '48' => 'PPT',
        '50' => 'NIT extranjero',
        '91' => 'NUIP',
    ],

    'tax_schemes' => [
        '01' => 'IVA',
        '04' => 'INC',
        'ZA' => 'IVA e INC',
        'ZZ' => 'No aplica',
    ],

    'fiscal_responsibilities' => [
        'O-13' => 'Gran contribuyente',
        'O-15' => 'Autorretenedor',
        'O-23' => 'Agente de retención IVA',
        'O-47' => 'Régimen simple de tributación',
        'O-48' => 'Impuesto sobre las ventas (IVA)',
        'O-49' => 'No responsable de IVA',
        'R-99-PN' => 'No aplica',
    ],

    'units' => [
        '94' => 'Unidad',
        'KGM' => 'Kilogramo',
        'GRM' => 'Gramo',
        'LTR' => 'Litro',
        'MLT' => 'Mililitro',
        'MTR' => 'Metro',
        'MTK' => 'Metro cuadrado',
        'HUR' => 'Hora',
        'DAY' => 'Día',
        'BX' => 'Caja',
        'PK' => 'Paquete',
        'DZN' => 'Docena',
    ],

];
