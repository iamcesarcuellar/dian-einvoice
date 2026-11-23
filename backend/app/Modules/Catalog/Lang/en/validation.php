<?php

// The names of the fields of each request, as validation messages write them, and the messages of their own. A null
// name keeps the framework's: the field's key, its underscores as spaces.
return [

    'attributes' => [
        'contact' => [
            'organization_type' => null,
            'identification_type' => null,
            'identification_number' => 'identification number',
            'check_digit' => null,
            'legal_name' => 'name',
            'trade_name' => null,
            'tax_scheme' => null,
            'fiscal_responsibilities' => 'fiscal responsibilities',
            'email' => null,
            'phone' => null,
            'address' => null,
            'country_code' => 'country',
            'municipality_code' => 'municipality',
            'postal_code' => null,
            'is_active' => null,
        ],
        'item' => [
            'code' => null,
            'name' => null,
            'description' => null,
            'type' => null,
            'unit_code' => 'unit',
            'unit_price' => 'unit price',
            'tax_id' => 'tax',
            'standard_scheme' => 'standard',
            'standard_code' => 'standard code',
            'is_active' => null,
        ],
        'rate' => [
            'dian_code' => 'type',
            'name' => null,
            'rate' => null,
            'is_active' => null,
        ],
    ],

    'messages' => [
        'contact' => [
            'identification_number.regex' => 'Use only digits for a NIT (without the check digit) and only letters or digits otherwise.',
            'identification_number.unique' => 'A contact with this identification already exists.',
            'check_digit.prohibited' => 'Only a NIT has a check digit.',
            'country_code.regex' => 'Use the two-letter ISO country code, such as CO.',
        ],
        'item' => [
            'code.regex' => 'Use letters, digits, dots, dashes, slashes or underscores in the code.',
            'code.unique' => 'Another item already uses this code.',
        ],
        'taxes' => [
            'dian_code.prohibited' => 'The type of one of the default taxes cannot be changed.',
            'name.unique' => 'Another rate already uses this name.',
        ],
        'withholdings' => [
            'dian_code.prohibited' => 'The type of one of the default withholdings cannot be changed.',
            'name.unique' => 'Another rate already uses this name.',
        ],
    ],

    'municipality_required' => 'Choose the municipality of a contact in Colombia.',
    'municipality_abroad' => 'Only contacts in Colombia have a municipality.',

];
