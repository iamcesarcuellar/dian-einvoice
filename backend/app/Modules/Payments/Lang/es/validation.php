<?php

// The Spanish names of the fields of each request, as validation messages write them, and their own messages.

return [

    'attributes' => [
        'payment' => [
            'contact_id' => 'cliente',
            'payment_date' => 'fecha',
            'payment_means_code' => 'medio de pago',
            'reference' => 'referencia',
            'notes' => 'observaciones',
            'amount' => 'valor',
            'allocations' => 'facturas',
            'allocations.*' => 'factura',
            'allocations.*.document_id' => 'factura',
            'allocations.*.amount' => 'valor por aplicar',
        ],
        'void' => [
            'reason' => 'motivo',
        ],
    ],

    'messages' => [
        'payment' => [
            'payment_date.before_or_equal' => 'La fecha del pago no puede ser posterior a hoy.',
        ],
    ],

];
