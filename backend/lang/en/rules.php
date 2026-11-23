<?php

// The messages of the application's own validation rules (app/Rules and the modules' Rules folders).
return [

    'decimal' => [
        'string' => 'The :attribute must be sent as a string, such as "1234.50".',
        'number' => 'The :attribute must be a number of zero or more, with up to :scale decimals.',
        'max' => 'The :attribute may not be greater than :max.',
    ],

    'fiscal_responsibilities' => [
        'empty' => 'The :attribute must list at least one fiscal responsibility.',
        'unknown' => 'The :attribute contains an unknown code.',
        'repeated' => 'The :attribute contains repeated codes.',
        'not_applicable' => 'R-99-PN (not applicable) cannot be combined with other fiscal responsibilities.',
    ],

];
