<?php

// The Spanish names of the fields of each request, as validation messages write them, and their own messages.

return [

    'attributes' => [
        'settings' => [
            'environment' => 'ambiente',
            'software_id' => 'ID del software',
            'software_pin' => 'PIN del software',
            'test_set_id' => 'ID del set de pruebas',
        ],
        'certificate' => [
            'file' => 'archivo del certificado',
            'password' => 'contraseña',
        ],
        'simulator' => [
            'mode' => 'modo',
            'rule' => 'regla',
            'delay_ms' => 'demora',
            'failure_rate' => 'porcentaje de fallas',
            'count' => 'número de peticiones',
        ],
    ],

    'messages' => [
        'settings' => [
            'environment.enum' => 'Solo está disponible el ambiente de pruebas (2). La producción no hace parte de este proyecto.',
            'software_id.uuid' => 'El ID del software es el identificador que asignó la DIAN, como 56f2ae4e-9812-4fad-9255-08fcfcd5ccb0.',
            'test_set_id.uuid' => 'El ID del set de pruebas es el identificador que la DIAN asignó al set de pruebas de habilitación.',
            'software_pin.regex' => 'El PIN del software no puede tener espacios.',
        ],
        'certificate' => [
            'file.extensions' => 'Sube un archivo de certificado .p12 o .pfx.',
        ],
    ],

];
