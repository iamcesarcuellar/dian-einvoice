<?php

use Pdo\Mysql;

// Merged with the framework defaults: the MySQL connection, as the framework defines it plus what the functions
// of the emulated cloud and of production need. Locally it connects as the single user of compose
// (backend/.env) and nothing else here applies. In the functions:
//  - the credentials come from the secret of the identity of the database of each function (DB_SECRETS_ID):
//    the migrations identity in the console function, the application identity in every other one; when the
//    database refuses them, as after a rotation, the connector reads that secret again and retries once
//    (App\Support\Database\RefreshingMySqlConnector);
//  - every connection is encrypted with TLS (DB_SSL_CA, the bundle of the RDS certificate authorities in the
//    image), and production also verifies the certificate of the server; the emulated cloud does not
//    (DB_SSL_VERIFY_SERVER_CERT=false), as the MySQL of compose signs its own for another name.
$tlsCa = env('DB_SSL_CA', env('MYSQL_ATTR_SSL_CA'));
$tls = is_string($tlsCa) && $tlsCa !== '';

return [

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'mask_bindings_in_exception_messages' => env('DB_MASK_BINDINGS', false),
            'strict' => true,
            'engine' => null,
            // The secret the credentials of this process come from, read again once when the database refuses them.
            'secrets_id' => env('DB_SECRETS_ID'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => $tls ? $tlsCa : null,
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $tls ? (bool) env('DB_SSL_VERIFY_SERVER_CERT', true) : null,
            ], fn (mixed $value): bool => $value !== null) : [],
        ],

    ],

    // The application identity of the database, which the console function provisions with its own, the
    // migrations identity (php artisan db:application-identity).
    'application_identity' => [
        'secret' => env('DB_APPLICATION_SECRETS_ID'),
    ],

];
