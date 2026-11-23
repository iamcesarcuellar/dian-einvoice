<?php

// The texts the electronic document chain stores for a company whose documents are in Spanish: the reason a step
// failed, which the timeline shows as it is, and what each transmission attempt recorded.

return [

    'unexpected' => 'Error inesperado: :error',
    'job_failed' => 'el trabajo falló.',
    'generation_failed' => 'Falló la generación: :error',
    'signing_failed' => 'Falló la firma: :error',
    'attempt_failed' => 'Intento :attempt de :max: :error',
    'no_response' => 'sin respuesta',
    'interrupted' => 'No quedó registrada ninguna respuesta: el proceso se detuvo durante la llamada.',
    'registration_failed' => 'No se pudo registrar la empresa en el simulador local: :error',

    // What a call to the DIAN web service, here the local simulator, recorded when it got no result.
    'gateway' => [
        'not_connected' => 'No se pudo conectar. :detail',
        'timeout' => 'Sin respuesta en :seconds s. :detail',
        'closed' => 'La conexión se cerró sin respuesta. :detail',
        'no_result' => 'HTTP :status sin un resultado de :operation.',
    ],

];
