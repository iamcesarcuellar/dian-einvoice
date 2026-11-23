<?php

// The email that delivers the invoice, or one of its notes, to the customer, in Spanish. The subject follows annex
// 1.9, section 9.1, and has no text to translate.

return [

    'preheader' => ':company te envió :document :number.',
    'greeting' => 'Hola:',
    'intro' => ':company te envió :document :number.',
    'documents' => [
        'invoice' => 'la factura electrónica de venta',
        'credit_note' => 'la nota crédito',
        'debit_note' => 'la nota débito',
    ],
    'company' => 'Emitida por',
    'numbers' => [
        'invoice' => 'Factura',
        'credit_note' => 'Nota crédito',
        'debit_note' => 'Nota débito',
    ],
    'total' => 'Total',
    'due_date' => 'Fecha de vencimiento',
    'cash' => 'Pago de contado, sin fecha de vencimiento',
    'corrects' => 'Corrige la factura',
    'attachment' => 'El archivo zip adjunto :file trae el AttachedDocument (el documento firmado con su validación) y su PDF.',
    'reply' => 'Responde este correo para escribirle a :company.',
    'test_title' => 'Documento de prueba',
    'test_notice' => 'Este documento se emitió en el ambiente de habilitación (pruebas) de la DIAN y lo validó un simulador local, no la DIAN. No tiene validez tributaria. Un buzón local de desarrollo atrapó este correo, que nunca salió del computador que lo envió.',
    'signature' => 'Enviado por :company con dian-einvoice.',

];
