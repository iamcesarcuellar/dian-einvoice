<?php

// The names of the taxes and withholdings every new company starts with, in the language of its documents. The rates
// are common values to review with an accountant.
return [

    'taxes' => [
        'iva_19' => 'IVA 19 %',
        'iva_5' => 'IVA 5 %',
        'iva_0' => 'IVA 0 % (exempt)',
        'inc_8' => 'INC 8 %',
    ],

    'withholdings' => [
        'rete_fuente_purchases' => 'ReteFuente 2,5 % (purchases)',
        'rete_fuente_services' => 'ReteFuente 4 % (services)',
        'rete_fuente_fees' => 'ReteFuente 11 % (fees)',
        'rete_iva' => 'ReteIVA 15 %',
    ],

];
