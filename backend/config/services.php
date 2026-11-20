<?php

// Merged with the framework defaults. SES sends the email of the emulated cloud (MAIL_MAILER=ses-v2):
// Laravel's SES v2 transport with the shared AWS settings of config/aws.php and the sending identity of
// the CDK app (MAIL_FROM_ADDRESS). Locally email goes over SMTP to the local mailbox and this is unused.
return [

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('AWS_ENDPOINT_URL'),
        'http' => [
            'connect_timeout' => (float) env('AWS_CONNECT_TIMEOUT', 2),
            // Well below the 35 s a queued job may run, as the SMTP timeout.
            'timeout' => (float) env('MAIL_TIMEOUT', 10),
        ],
    ],

];
