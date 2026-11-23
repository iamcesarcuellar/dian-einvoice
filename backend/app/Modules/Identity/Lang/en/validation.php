<?php

// The names of the fields of each request, as validation messages write them, and the messages of their own. A null
// name keeps the framework's: the field's key, its underscores as spaces.
return [

    'attributes' => [
        // A company: registration (under company.), a new company and its settings.
        'company' => [
            'name' => 'company name',
            'legal_name' => 'legal name',
            'identification_number' => 'NIT',
            'check_digit' => 'check digit',
            'organization_type' => 'organization type',
            'tax_scheme' => 'tax scheme',
            'fiscal_responsibilities' => 'fiscal responsibilities',
            'registration_number' => 'registration number',
            'email' => null,
            'phone' => null,
            'address' => null,
            'municipality_code' => 'municipality',
            'postal_code' => 'postal code',
            'document_language' => null,
        ],
        'register' => [
            'name' => null,
            'email' => null,
            'password' => null,
            'company' => null,
        ],
        'login' => [
            'email' => null,
            'password' => null,
        ],
        'reset' => [
            'token' => null,
            'email' => null,
            'password' => null,
        ],
        'invitation' => [
            'email' => null,
            'role' => null,
            'name' => null,
            'password' => null,
        ],
        'password' => [
            'current_password' => null,
            'password' => 'new password',
        ],
        'profile' => [
            'name' => null,
        ],
    ],

    'messages' => [
        'company' => [
            'identification_number.regex' => 'The NIT must have between 5 and 15 digits, without the check digit.',
            'identification_number.unique' => 'A company with this NIT is already registered.',
            'postal_code.regex' => 'The postal code must have 6 digits.',
        ],
    ],

    'current_password' => 'The current password is not correct.',
    'check_digit' => 'The check digit does not match the NIT.',

];
