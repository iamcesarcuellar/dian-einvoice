<?php

// The names of the fields of each request, as validation messages write them, and the messages of their own. A null
// name keeps the framework's: the field's key, its underscores as spaces (the index of an allocation included).
return [

    'attributes' => [
        'payment' => [
            'contact_id' => 'customer',
            'payment_date' => 'date',
            'payment_means_code' => 'payment means',
            'reference' => null,
            'notes' => null,
            'amount' => null,
            'allocations' => 'invoices',
            'allocations.*' => null,
            'allocations.*.document_id' => 'invoice',
            'allocations.*.amount' => 'amount to apply',
        ],
        'void' => [
            'reason' => null,
        ],
    ],

    'messages' => [
        'payment' => [
            'payment_date.before_or_equal' => 'The payment date cannot be later than today.',
        ],
    ],

];
