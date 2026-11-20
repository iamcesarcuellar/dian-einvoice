<?php

// Merged with the framework defaults. Email goes through Laravel's mailer over SMTP: locally to the
// development mailbox (compose service mail, Mailpit), which keeps every message and relays none. The
// emulated cloud sends through SES instead (MAIL_MAILER=ses, the SES v2 API, config/services.php).
return [

    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'mail'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // Seconds to wait for the mail server; well below the 35 s a queued job may run.
            'timeout' => (float) env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', 'localhost'),
        ],

        // Amazon SES through its v2 API (SendEmail with the raw message), with the sending identity of
        // the CDK app.
        'ses' => [
            'transport' => 'ses-v2',
        ],

    ],

    // The platform sends; each email carries the name of the issuing company and replies go to it.
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'documents@dian-einvoice.example'),
        'name' => env('MAIL_FROM_NAME', 'dian-einvoice'),
    ],

];
