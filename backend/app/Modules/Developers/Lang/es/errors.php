<?php

// The details of the error responses of API keys, idempotency, rate limits, webhooks and the local webhook
// receiver, in Spanish.

return [

    'idempotency_key' => 'La Idempotency-Key debe tener de 1 a 255 caracteres ASCII visibles, como un UUID.',
    'idempotency_mismatch' => 'Esta Idempotency-Key ya se usó con otra petición (método, ruta o cuerpo). Usa una llave nueva para una petición nueva.',
    'idempotency_running' => 'Una petición con esta Idempotency-Key todavía está en curso. Espera a que termine y repítela para obtener su respuesta.',
    'rate_limited' => 'Esta llave de API permite :limit peticiones por minuto. Vuelve a intentarlo en :seconds segundos.',
    'session_only' => 'Las llaves de API no pueden usar esta ruta: es solo para un propietario o un administrador con sesión iniciada en la aplicación web.',
    'key_invalid' => 'La llave de API no es válida. Envía una de las llaves de API de esta plataforma en la cabecera Authorization: Bearer dei_...',
    'key_revoked' => 'La llave de API :key fue revocada.',
    'key_expired' => 'La llave de API :key venció el :date.',
    'delivery_running' => 'Esta entrega todavía está en curso.',
    'subscription_off' => 'La suscripción está desactivada. Primero actívala.',
    'receiver_down' => 'El receptor local de webhooks no responde. Inicia el stack con make up.',

];
