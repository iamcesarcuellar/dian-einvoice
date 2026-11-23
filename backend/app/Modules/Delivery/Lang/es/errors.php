<?php

// The details of the error responses of the delivery to the customer and its files, in Spanish.

return [

    'no_email' => 'El cliente no tiene correo. Escribe el correo al que quieres enviar la factura.',
    'regenerate_unavailable' => 'Regenerar PDF solo está disponible cuando el PDF falló o no existe.',
    'not_accepted' => 'Solo se entregan al cliente las facturas que el simulador local aceptó.',
    'running' => 'La entrega de esta factura está en curso en este momento.',
    'drafts' => 'Los borradores no se entregan. Primero emite el documento.',
    'missing' => [
        'pdf' => 'El PDF de este documento aún no está disponible.',
        'attached_document' => 'El AttachedDocument de este documento aún no está disponible.',
        'package' => 'El zip de este documento aún no está disponible.',
    ],

];
