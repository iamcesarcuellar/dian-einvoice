<?php

// The Spanish names of the fields of each request, as validation messages write them, and their own messages.

return [

    'attributes' => [
        'contact' => [
            'organization_type' => 'tipo de persona',
            'identification_type' => 'tipo de identificación',
            'identification_number' => 'número de identificación',
            'check_digit' => 'dígito de verificación',
            'legal_name' => 'nombre',
            'trade_name' => 'nombre comercial',
            'tax_scheme' => 'tributo',
            'fiscal_responsibilities' => 'responsabilidades fiscales',
            'email' => 'correo',
            'phone' => 'teléfono',
            'address' => 'dirección',
            'country_code' => 'país',
            'municipality_code' => 'municipio',
            'postal_code' => 'código postal',
            'is_active' => 'activo',
        ],
        'item' => [
            'code' => 'código',
            'name' => 'nombre',
            'description' => 'descripción',
            'type' => 'tipo',
            'unit_code' => 'unidad',
            'unit_price' => 'precio unitario',
            'tax_id' => 'impuesto',
            'standard_scheme' => 'estándar',
            'standard_code' => 'código estándar',
            'is_active' => 'activo',
        ],
        'rate' => [
            'dian_code' => 'tipo',
            'name' => 'nombre',
            'rate' => 'tarifa',
            'is_active' => 'activa',
        ],
    ],

    'messages' => [
        'contact' => [
            'identification_number.regex' => 'Usa solo dígitos para un NIT (sin el dígito de verificación) y solo letras o dígitos para las demás identificaciones.',
            'identification_number.unique' => 'Ya existe un contacto con esta identificación.',
            'check_digit.prohibited' => 'Solo un NIT tiene dígito de verificación.',
            'country_code.regex' => 'Usa el código ISO de dos letras del país, como CO.',
        ],
        'item' => [
            'code.regex' => 'Usa letras, dígitos, puntos, guiones, barras o guiones bajos en el código.',
            'code.unique' => 'Otro ítem ya usa este código.',
        ],
        'taxes' => [
            'dian_code.prohibited' => 'El tipo de un impuesto predeterminado no se puede cambiar.',
            'name.unique' => 'Otra tarifa ya usa este nombre.',
        ],
        'withholdings' => [
            'dian_code.prohibited' => 'El tipo de una retención predeterminada no se puede cambiar.',
            'name.unique' => 'Otra tarifa ya usa este nombre.',
        ],
    ],

    'municipality_required' => 'Elige el municipio de un contacto en Colombia.',
    'municipality_abroad' => 'Solo los contactos en Colombia tienen municipio.',

];
