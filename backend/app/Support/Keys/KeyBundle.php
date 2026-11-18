<?php

namespace App\Support\Keys;

use SensitiveParameter;

/**
 * A PKCS#12 bundle and the password that opens it.
 */
final readonly class KeyBundle
{
    public function __construct(
        public string $pkcs12,
        #[SensitiveParameter] public string $password,
    ) {}
}
