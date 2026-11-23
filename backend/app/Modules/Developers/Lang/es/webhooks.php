<?php

// What a webhook delivery stores about its attempts for a company whose documents are in Spanish, and why a webhook
// URL is refused, in Spanish.

return [

    'https_only' => 'Aquí solo reciben webhooks los endpoints https.',
    'https_port_only' => 'Las URL de los webhooks deben usar el puerto 443, el puerto predeterminado de https: las entregas no salen por ningún otro puerto.',
    'no_host' => 'La URL no tiene host.',
    'local_host' => 'La URL apunta a esta máquina.',
    'unresolved' => 'El host :host no resuelve.',
    'private' => 'El host :host resuelve a :address, una dirección privada, local o reservada.',
    'no_public_address' => 'La URL no tiene una dirección pública.',
    'timeout' => 'Sin respuesta en :seconds segundos.',
    'http_error' => 'El endpoint respondió HTTP :status.',
    'unexpected' => 'Error inesperado: :error',
    'job_failed' => 'el trabajo falló.',
    'interrupted' => 'No quedó registrado ningún resultado: el proceso se detuvo durante la llamada.',
    'switched_off' => 'No se envió: la suscripción está desactivada.',
    'disabled' => 'Se desactivó sola después de :failures entregas fallidas seguidas. Arregla el endpoint y vuelve a activarla.',

];
