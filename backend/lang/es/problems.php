<?php

// The fixed texts of the error responses (application/problem+json, App\Http\ProblemDetails) in Spanish: the title
// of each status and the details that do not depend on the request.

return [

    'titles' => [
        '400' => 'Petición inválida',
        '401' => 'No autenticado',
        '403' => 'Prohibido',
        '404' => 'No encontrado',
        '405' => 'Método no permitido',
        '406' => 'No aceptable',
        '409' => 'Conflicto',
        '410' => 'Ya no existe',
        '413' => 'Contenido demasiado grande',
        '415' => 'Tipo de contenido no admitido',
        '419' => 'Token CSRF no válido',
        '422' => 'Contenido no procesable',
        '423' => 'Bloqueado',
        '429' => 'Demasiadas peticiones',
        '500' => 'Error interno del servidor',
        '502' => 'Puerta de enlace incorrecta',
        '503' => 'Servicio no disponible',
        '504' => 'Tiempo de espera agotado',
    ],

    'invalid' => 'Los datos enviados no son válidos.',
    'unauthenticated' => 'Inicia sesión en la aplicación web o envía una llave de API en la cabecera Authorization: Bearer dei_...',
    'not_found' => 'El recurso que pediste no existe.',
    'csrf' => 'Falta el token CSRF o ya venció. Recarga la página e inténtalo de nuevo.',
    'server_error' => 'Algo falló de nuestro lado.',
    'unhealthy' => 'Un servicio que la API necesita no responde.',

    // Errors the framework raises with a text of its own, which stays as it is in English.
    'framework' => [
        '405' => 'Este método no está permitido en esta ruta.',
        '413' => 'La petición es demasiado grande.',
        '429' => 'Demasiadas peticiones. Espera un momento y vuelve a intentarlo.',
    ],

];
