<?php

// SYNTHETIC DEMO DATA in Spanish: the free lines, the descriptions of the demo notes and the reason of the voided
// payment.
return [

    'lines' => [
        'export_documentation' => 'Trámite de documentos de exportación',
        'late_interest' => 'Intereses de mora, diez días después del vencimiento',
    ],

    'notes' => [
        'paper_discount' => 'Descuento del 10 % en las veinticinco resmas de papel, acordado por una entrega tardía.',
        'wrong_customer' => 'La factura se emitió a un cliente equivocado, así que se anula completa.',
        'damaged_return' => 'Devolución de una silla de oficina y cinco resmas de papel que llegaron dañadas.',
        'late_interest' => 'Intereses de mora: la factura se pagó diez días después de su vencimiento.',
        'chair_refused' => 'Devolución de una silla de oficina que el cliente no aceptó en la entrega.',
        'fewer_guests' => 'Al almuerzo corporativo asistieron cinco invitados menos de los cuarenta facturados.',
        'consulting_rebate' => 'Rebaja del 10 % en las horas de asesoría, acordada después de la revisión del proyecto.',
    ],

    'voids' => [
        'returned_check' => 'El banco devolvió el cheque por fondos insuficientes.',
    ],

];
