<?php

// English names of the DIAN codes shown on the PDF, the same as the reference labels of the web app.
return [

    // Concepts of the notes (tables 13.2.4 and 13.2.5), as the reference labels of the web app.
    'credit_note_concepts' => [
        '1' => 'Partial return of goods or partial rejection of a service',
        '2' => 'Void of the electronic invoice',
        '3' => 'Partial or total rebate or discount',
        '4' => 'Price adjustment',
        '5' => 'Commercial discount for prompt payment',
        '6' => 'Commercial discount for sales volume',
    ],

    'debit_note_concepts' => [
        '1' => 'Interest',
        '2' => 'Expenses to collect',
        '3' => 'Change of the value',
        '4' => 'Other',
    ],

    'payment_forms' => [
        '1' => 'Cash',
        '2' => 'Credit',
    ],

    'payment_means' => [
        '10' => 'Cash',
        '20' => 'Check',
        '42' => 'Bank deposit',
        '47' => 'Bank debit transfer',
        '48' => 'Credit card',
        '49' => 'Debit card',
        'ZZZ' => 'Other',
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
        '50' => 'Foreign NIT',
        '91' => 'NUIP',
    ],

    'tax_schemes' => [
        '01' => 'IVA',
        '04' => 'INC',
        'ZA' => 'IVA and INC',
        'ZZ' => 'Not applicable',
    ],

    'fiscal_responsibilities' => [
        'O-13' => 'Large taxpayer',
        'O-15' => 'Self-withholder',
        'O-23' => 'IVA withholding agent',
        'O-47' => 'Simple tax regime',
        'O-48' => 'Sales tax (IVA)',
        'O-49' => 'Not responsible for IVA',
        'R-99-PN' => 'Not applicable',
    ],

    'units' => [
        '94' => 'Unit',
        'KGM' => 'Kilogram',
        'GRM' => 'Gram',
        'LTR' => 'Liter',
        'MLT' => 'Milliliter',
        'MTR' => 'Meter',
        'MTK' => 'Square meter',
        'HUR' => 'Hour',
        'DAY' => 'Day',
        'BX' => 'Box',
        'PK' => 'Pack',
        'DZN' => 'Dozen',
    ],

];
