<?php

// The details of the error responses of API keys, idempotency, rate limits, webhooks and the local webhook receiver.
return [

    'idempotency_key' => 'Idempotency-Key must be 1 to 255 visible ASCII characters, such as a UUID.',
    'idempotency_mismatch' => 'This Idempotency-Key was already used with another request (method, path or body). Use a new key for a new request.',
    'idempotency_running' => 'A request with this Idempotency-Key is still in progress. Wait for it to finish, then repeat it to get its response.',
    'rate_limited' => 'This API key allows :limit requests per minute. Try again in :seconds seconds.',
    'session_only' => 'API keys cannot use this endpoint: it is only for an owner or admin signed in to the web app.',
    'key_invalid' => "The API key is not valid. Send one of this platform's API keys in the Authorization header: Bearer dei_...",
    'key_revoked' => 'The API key :key was revoked.',
    'key_expired' => 'The API key :key expired on :date.',
    'delivery_running' => 'This delivery is still in progress.',
    'subscription_off' => 'The subscription is switched off. Activate it first.',
    'receiver_down' => 'The local webhook receiver is not responding. Start the stack with make up.',

];
