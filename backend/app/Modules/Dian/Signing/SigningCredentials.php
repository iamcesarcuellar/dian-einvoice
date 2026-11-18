<?php

namespace App\Modules\Dian\Signing;

use SensitiveParameter;

/**
 * What a signature needs: the signing certificate, its private key and the rest of its chain,
 * all in PEM.
 */
final readonly class SigningCredentials
{
    /**
     * @param  list<string>  $chainPems  Issuer certificates, from the one that issued the signing certificate up.
     */
    public function __construct(
        public string $certificatePem,
        #[SensitiveParameter] public string $privateKeyPem,
        public array $chainPems = [],
    ) {}
}
