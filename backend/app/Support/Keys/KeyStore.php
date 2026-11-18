<?php

namespace App\Support\Keys;

use SensitiveParameter;

/**
 * Where signing keys live: a PKCS#12 bundle (private key and certificate chain) with its
 * password, under an opaque reference. The local adapter keeps them encrypted with the app key;
 * Infrastructure swaps in a key management service without touching the callers.
 */
interface KeyStore
{
    /** Stores the bundle of a company and returns its reference. */
    public function put(string $companyId, string $pkcs12, #[SensitiveParameter] string $password): string;

    public function get(string $reference): KeyBundle;

    public function delete(string $reference): void;
}
