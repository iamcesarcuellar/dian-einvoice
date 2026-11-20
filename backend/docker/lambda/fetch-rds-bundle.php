<?php

// Build step of the image of the functions (target lambda of the Dockerfile): the global bundle of the
// certificate authorities of Amazon RDS, which sign the server certificate of the managed database. The
// functions of prod verify that certificate on every connection (DB_SSL_CA in infra/functions/serverless.yml).
// The bundle is pinned by its SHA-256: one AWS changes fails the build until the pin is updated.
//
//   php fetch-rds-bundle.php <sha256> <destination>

[, $expected, $destination] = $argv + [null, '', ''];
$url = 'https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem';
$bundle = file_get_contents($url);

if (! is_string($bundle) || hash('sha256', $bundle) !== $expected) {
    fwrite(STDERR, "The bundle at {$url} is not the pinned one (SHA-256 {$expected}).\n");
    exit(1);
}

preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $blocks);
$certificates = array_filter($blocks[0], fn (string $block): bool => openssl_x509_parse($block) !== false);

if (count($certificates) === 0 || count($certificates) !== count($blocks[0])) {
    fwrite(STDERR, "The bundle holds certificates that do not parse.\n");
    exit(1);
}

if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true)) {
    exit(1);
}

file_put_contents($destination, $bundle);
chmod($destination, 0644);
echo count($certificates)." certificate authorities of Amazon RDS in {$destination}.\n";
