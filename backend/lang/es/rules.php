<?php

// The messages of the application's own validation rules (app/Rules and the modules' Rules folders), in Spanish.

return [

    'decimal' => [
        'string' => 'El campo :attribute debe enviarse como texto, por ejemplo "1234.50".',
        'number' => 'El campo :attribute debe ser un número de cero o más, con hasta :scale decimales.',
        'max' => 'El campo :attribute no puede ser mayor que :max.',
    ],

    'fiscal_responsibilities' => [
        'empty' => 'El campo :attribute debe tener al menos una responsabilidad fiscal.',
        'unknown' => 'El campo :attribute tiene un código desconocido.',
        'repeated' => 'El campo :attribute tiene códigos repetidos.',
        'not_applicable' => 'R-99-PN (no aplica) no se puede combinar con otras responsabilidades fiscales.',
    ],

];
