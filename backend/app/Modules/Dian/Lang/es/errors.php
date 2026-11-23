<?php

// The details of the error responses of the DIAN settings, the signing certificate, the local simulator and the
// actions on an electronic document, in Spanish.

return [

    'attempt_running' => 'Hay un intento en curso en este momento.',
    'retry_unavailable' => 'Reintentar ahora solo está disponible mientras una transmisión espera su siguiente intento, o después de que el documento falló.',
    'resend_unavailable' => 'Reenviar solo está disponible después de que el simulador local rechazó el documento.',
    'resend_settings' => 'Completa el ID del software y el PIN en Configuración → DIAN antes de reenviar.',
    'unknown_rule' => 'El simulador local no implementa esta regla.',
    'simulator_down' => 'El simulador local de la DIAN no responde. Inícialo con make up.',
    'simulator_unreachable' => 'El simulador local de la DIAN no responde.',
    'simulator_http' => 'El simulador local de la DIAN respondió HTTP :status: :error',
    'no_details' => 'sin detalles',

    'certificate' => [
        'password' => 'No se pudo abrir el archivo con esta contraseña. Revisa la contraseña y que el archivo sea un certificado .p12 o .pfx.',
        'contents' => 'El archivo debe tener la clave privada y su certificado.',
        'rsa' => 'La clave privada debe ser una clave RSA.',
        'pair' => 'La clave privada no corresponde al certificado.',
    ],

    // What issuing needs and is missing, by the screen where each item is completed.
    'requirements' => [
        'company' => [
            'legal_name' => 'Agrega la razón social de la empresa.',
            'identification_number' => 'Agrega el NIT de la empresa.',
            'organization_type' => 'Elige el tipo de persona de la empresa.',
            'tax_scheme' => 'Elige el tributo de la empresa.',
            'fiscal_responsibilities' => 'Elige las responsabilidades fiscales de la empresa.',
            'address' => 'Agrega la dirección de la empresa.',
            'municipality_code' => 'Elige el municipio de la empresa.',
            'email' => 'Agrega el correo de facturación de la empresa.',
        ],
        'software_id' => 'Agrega el ID del software en la configuración DIAN.',
        'software_pin' => 'Agrega el PIN del software en la configuración DIAN.',
        'technical_key' => 'Agrega la clave técnica de la numeración :numbering.',
        'resolution_date' => 'Agrega la fecha de la resolución de la numeración :numbering.',
        'customer_identification' => 'Agrega la identificación del cliente.',
        'certificate' => 'Agrega un certificado de firma en la configuración DIAN.',
        'certificate_expired' => 'El certificado de firma venció el :date. Sube uno vigente en la configuración DIAN.',
        'certificate_upcoming' => 'El certificado de firma rige desde el :date. Usa uno que esté vigente hoy.',
    ],

];
