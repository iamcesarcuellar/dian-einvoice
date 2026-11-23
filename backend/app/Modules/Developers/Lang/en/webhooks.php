<?php

// What a webhook delivery stores about its attempts, in the language of the company's documents, and why a webhook
// URL is refused, in the language of the request that saves it.
return [

    'https_only' => 'Only https endpoints receive webhooks here.',
    'https_port_only' => 'Webhook URLs must use port 443, the default port of https: deliveries leave on no other port.',
    'no_host' => 'The URL has no host.',
    'local_host' => 'The URL points to this machine.',
    'unresolved' => 'The host :host does not resolve.',
    'private' => 'The host :host resolves to :address, a private, local or reserved address.',
    'no_public_address' => 'The URL has no public address.',
    'timeout' => 'No response within :seconds seconds.',
    'http_error' => 'The endpoint returned HTTP :status.',
    'unexpected' => 'Unexpected error: :error',
    'job_failed' => 'the job failed.',
    'interrupted' => 'No outcome was recorded: the worker stopped during the call.',
    'switched_off' => 'Not sent: the subscription is switched off.',
    'disabled' => 'Switched off automatically after :failures failed deliveries in a row. Fix the endpoint, then activate it again.',

];
