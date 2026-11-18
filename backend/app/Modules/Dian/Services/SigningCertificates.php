<?php

namespace App\Modules\Dian\Services;

use App\Modules\Dian\Models\SigningCertificate;
use App\Modules\Dian\Signing\SigningCredentials;
use App\Modules\Dian\Signing\X509;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Keys\KeyStore;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * The signing certificate of the current company: uploaded as a .p12 or .pfx file, or generated
 * as a synthetic self-signed test certificate. The bundle and its password go to the key store;
 * only public metadata is kept in signing_certificates and returned by the API.
 */
final class SigningCertificates
{
    private const int TEST_KEY_BITS = 2048;

    private const int TEST_YEARS = 2;

    private const string TEST_MARK = 'TEST CERTIFICATE';

    /** Upper bound of the CommonName and Organization attributes (RFC 5280). */
    private const int NAME_LENGTH = 64;

    public function __construct(
        private readonly KeyStore $keys,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function current(): ?SigningCertificate
    {
        return SigningCertificate::current();
    }

    /**
     * Replaces the certificate with an uploaded PKCS#12 file, after checking it opens with the
     * password and holds an RSA private key with its certificate.
     */
    public function upload(string $pkcs12, #[SensitiveParameter] string $password): SigningCertificate
    {
        try {
            $credentials = $this->open($pkcs12, $password);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        return $this->replace($pkcs12, $password, $credentials, isTest: false);
    }

    /**
     * Replaces the certificate with a synthetic self-signed one: RSA 2048, SHA-256, valid for two
     * years from $validFrom (now by default), with the NIT and legal name of the company and the
     * TEST CERTIFICATE mark in its name. No accredited certification authority issued it.
     */
    public function generateTest(Company $company, ?CarbonInterface $validFrom = null): SigningCertificate
    {
        $validFrom = Carbon::instance($validFrom ?? Carbon::now())->utc()->startOfSecond();
        $validTo = $validFrom->copy()->addYears(self::TEST_YEARS);

        $options = [
            'config' => resource_path('dian/test-certificate.cnf'),
            'digest_alg' => 'sha256',
            'private_key_bits' => self::TEST_KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'test_signing',
        ];

        $key = openssl_pkey_new($options) ?: throw $this->openSslError('The test key could not be generated');
        $request = openssl_csr_new($this->testName($company), $key, $options);

        if ($request === false || $request === true) {
            throw $this->openSslError('The test certificate request could not be created');
        }

        $certificate = openssl_csr_sign($request, null, $key, 1, $options, random_int(1, PHP_INT_MAX))
            ?: throw $this->openSslError('The test certificate could not be signed');

        openssl_x509_export($certificate, $pem) ?: throw $this->openSslError('The test certificate could not be exported');

        // OpenSSL starts the validity now; set the exact two years, from $validFrom, and sign again.
        $pem = X509::pem(X509::withValidity(X509::der($pem), $validFrom, $validTo, $key));
        $publicKey = openssl_pkey_get_details($key)['key'] ?? '';

        if (openssl_x509_verify($pem, $publicKey) !== 1) {
            throw new RuntimeException('The re-dated test certificate does not verify.');
        }

        openssl_pkey_export($key, $keyPem, null, $options) ?: throw $this->openSslError('The test key could not be exported');

        $password = bin2hex(random_bytes(16));
        openssl_pkcs12_export($pem, $pkcs12, $keyPem, $password, ['friendly_name' => $this->testName($company)['commonName']])
            ?: throw $this->openSslError('The test certificate could not be bundled');

        return $this->replace($pkcs12, $password, new SigningCredentials($pem, $keyPem), isTest: true);
    }

    public function remove(): void
    {
        $certificate = $this->current();

        if ($certificate === null) {
            return;
        }

        DB::transaction(function () use ($certificate): void {
            $this->keys->delete($certificate->key_reference);
            $certificate->delete();
        });
    }

    /** Certificate, private key and chain, read from the key store to sign a document. */
    public function credentials(SigningCertificate $certificate): SigningCredentials
    {
        $bundle = $this->keys->get($certificate->key_reference);

        return $this->open($bundle->pkcs12, $bundle->password);
    }

    /**
     * @throws InvalidArgumentException When the bundle cannot be used to sign.
     */
    private function open(string $pkcs12, #[SensitiveParameter] string $password): SigningCredentials
    {
        if (! openssl_pkcs12_read($pkcs12, $parts, $password)) {
            throw new InvalidArgumentException('The file could not be opened with this password. Check the password, and that the file is a .p12 or .pfx certificate.');
        }

        if (blank($parts['pkey'] ?? null) || blank($parts['cert'] ?? null)) {
            throw new InvalidArgumentException('The file must contain the private key and its certificate.');
        }

        $key = openssl_pkey_get_private($parts['pkey']);

        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('The private key must be an RSA key.');
        }

        if (! openssl_x509_check_private_key($parts['cert'], $key)) {
            throw new InvalidArgumentException('The private key does not belong to the certificate.');
        }

        return new SigningCredentials($parts['cert'], $parts['pkey'], $this->chain($parts['cert'], array_values($parts['extracerts'] ?? [])));
    }

    /**
     * The other certificates of the bundle, ordered from the issuer of the signing certificate up.
     *
     * @param  list<string>  $others
     * @return list<string>
     */
    private function chain(string $certificate, array $others): array
    {
        $own = openssl_x509_fingerprint($certificate, 'sha256');
        $remaining = array_values(array_filter($others, fn (string $pem): bool => openssl_x509_fingerprint($pem, 'sha256') !== $own));
        $chain = [];
        $current = openssl_x509_parse($certificate) ?: [];

        while ($remaining !== [] && ($current['subject'] ?? null) !== ($current['issuer'] ?? null)) {
            $next = null;

            foreach ($remaining as $index => $pem) {
                if ((openssl_x509_parse($pem)['subject'] ?? null) === ($current['issuer'] ?? false)) {
                    $next = $index;
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $chain[] = $remaining[$next];
            $current = openssl_x509_parse($remaining[$next]) ?: [];
            array_splice($remaining, $next, 1);
        }

        return [...$chain, ...$remaining];
    }

    private function replace(string $pkcs12, #[SensitiveParameter] string $password, SigningCredentials $credentials, bool $isTest): SigningCertificate
    {
        $der = X509::der($credentials->certificatePem);
        $info = openssl_x509_parse($credentials->certificatePem) ?: throw new RuntimeException('The certificate could not be read.');

        return DB::transaction(function () use ($pkcs12, $password, $der, $info, $isTest): SigningCertificate {
            $previous = $this->current();

            if ($previous !== null) {
                $this->keys->delete($previous->key_reference);
                $previous->delete();
            }

            return SigningCertificate::create([
                'key_reference' => $this->keys->put($this->currentCompany->id(), $pkcs12, $password),
                'subject' => $this->displayName($info['subject'] ?? []),
                'issuer' => $this->displayName($info['issuer'] ?? []),
                'serial_number' => X509::serialNumber($der),
                'fingerprint_sha256' => hash('sha256', $der),
                'valid_from' => Carbon::createFromTimestampUTC($info['validFrom_time_t']),
                'valid_to' => Carbon::createFromTimestampUTC($info['validTo_time_t']),
                'is_test' => $isTest,
            ]);
        });
    }

    /**
     * A distinguished name for people to read, most specific attribute first: CN=..., O=..., C=CO.
     *
     * @param  array<string, string|list<string>>  $name
     */
    private function displayName(array $name): string
    {
        $parts = [];

        foreach ($name as $attribute => $values) {
            foreach ((array) $values as $value) {
                $parts[] = "{$attribute}={$value}";
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /**
     * @return array<string, string>
     */
    private function testName(Company $company): array
    {
        $legalName = trim($company->legal_name);
        $mark = ' '.self::TEST_MARK;

        return [
            'countryName' => 'CO',
            'organizationName' => mb_substr($legalName, 0, self::NAME_LENGTH),
            'commonName' => mb_substr($legalName, 0, self::NAME_LENGTH - mb_strlen($mark)).$mark,
            'serialNumber' => $company->identification_number,
        ];
    }

    private function openSslError(string $message): RuntimeException
    {
        return new RuntimeException($message.': '.(openssl_error_string() ?: 'unknown OpenSSL error').'.');
    }
}
