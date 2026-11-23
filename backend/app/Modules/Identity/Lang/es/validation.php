<?php

// The Spanish names of the fields of each request, as validation messages write them, and their own messages.

return [

    'attributes' => [
        // A company: registration (under company.), a new company and its settings.
        'company' => [
            'name' => 'nombre de la empresa',
            'legal_name' => 'razón social',
            'identification_number' => 'NIT',
            'check_digit' => 'dígito de verificación',
            'organization_type' => 'tipo de persona',
            'tax_scheme' => 'tributo',
            'fiscal_responsibilities' => 'responsabilidades fiscales',
            'registration_number' => 'matrícula mercantil',
            'email' => 'correo de facturación',
            'phone' => 'teléfono',
            'address' => 'dirección',
            'municipality_code' => 'municipio',
            'postal_code' => 'código postal',
            'document_language' => 'idioma de los documentos',
        ],
        'register' => [
            'name' => 'nombre completo',
            'email' => 'correo',
            'password' => 'contraseña',
            'company' => 'empresa',
        ],
        'login' => [
            'email' => 'correo',
            'password' => 'contraseña',
        ],
        'reset' => [
            'token' => 'token',
            'email' => 'correo',
            'password' => 'contraseña',
        ],
        'invitation' => [
            'email' => 'correo',
            'role' => 'rol',
            'name' => 'nombre completo',
            'password' => 'contraseña',
        ],
        'password' => [
            'current_password' => 'contraseña actual',
            'password' => 'nueva contraseña',
        ],
        'profile' => [
            'name' => 'nombre',
        ],
    ],

    'messages' => [
        'company' => [
            'identification_number.regex' => 'El NIT debe tener entre 5 y 15 dígitos, sin el dígito de verificación.',
            'identification_number.unique' => 'Ya hay una empresa registrada con este NIT.',
            'postal_code.regex' => 'El código postal debe tener 6 dígitos.',
        ],
    ],

    'current_password' => 'La contraseña actual no es correcta.',
    'check_digit' => 'El dígito de verificación no corresponde al NIT.',

];
