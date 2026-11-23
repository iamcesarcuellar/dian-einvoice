<?php

// The texts the delivery to the customer stores for a company whose documents are in Spanish: the reason a step
// failed, which the timeline shows as it is, and what each email attempt recorded.

return [

    'unexpected' => 'Error inesperado: :error',
    'job_failed' => 'el trabajo falló.',
    'attached_failed' => 'No se pudo construir el AttachedDocument: :error',
    'pdf_failed' => 'No se pudo generar el PDF: :error',
    'zip_failed' => 'No se pudo armar el zip: :error',
    'attempt_failed' => 'Intento :attempt de :max: :error',
    'no_outcome' => 'sin resultado',
    'interrupted' => 'No quedó registrado ningún resultado: el proceso se detuvo durante la llamada.',

    // Why the mail server did not take an email.
    'mailer' => [
        'invalid_address' => 'La dirección no es válida: :error',
        'server_returned' => 'El servidor de correo respondió :code: :error',
        'unreachable' => 'No se pudo contactar el servidor de correo: :error',
        'unexpected' => 'Error inesperado: :error',
    ],

];
