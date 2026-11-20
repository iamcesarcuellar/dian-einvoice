<?php

namespace App\Support\Keys;

use Aws\Kms\KmsClient;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * KMS adapter of KeyStore, with envelope encryption: every bundle gets a data key of its own from KMS
 * (GenerateDataKey under the signing key of the CDK app), is encrypted with it locally (AES-256-GCM) and only
 * the data key encrypted by KMS is stored next to it, in kms_key_store_entries. Reading a bundle asks KMS to
 * decrypt its data key. The plaintext data key lives only in memory, for one call, and APP_KEY plays no part.
 *
 * The encryption context names the company, the purpose and the entry: KMS refuses to decrypt a data key
 * outside the context it was made in, and the same context is the additional data of the cipher, so a row
 * copied to another company or another entry cannot be opened.
 */
final class KmsKeyStore implements KeyStore
{
    public const string CIPHER = 'aes-256-gcm';

    private const string PURPOSE = 'signing-certificate';

    public function __construct(
        private readonly KmsClient $kms,
        private readonly string $keyId,
    ) {}

    public function put(string $companyId, string $pkcs12, #[SensitiveParameter] string $password): string
    {
        $id = Str::lower((string) Str::ulid());
        $context = self::context($companyId, $id);

        $dataKey = $this->kms->generateDataKey([
            'KeyId' => $this->keyId,
            'KeySpec' => 'AES_256',
            'EncryptionContext' => $context,
        ]);

        $plaintextKey = self::bytes($dataKey['Plaintext'] ?? null, 'GenerateDataKey answered no plaintext key.');
        $nonce = random_bytes(12);
        $tag = '';
        $plaintext = json_encode(['pkcs12' => base64_encode($pkcs12), 'password' => $password], JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $plaintextKey, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($context));
        sodium_memzero($plaintextKey);
        sodium_memzero($plaintext);

        if ($ciphertext === false) {
            throw new RuntimeException('The signing key could not be encrypted.');
        }

        KmsKeyStoreEntry::create([
            'id' => $id,
            'company_id' => $companyId,
            'kms_key_id' => is_string($dataKey['KeyId'] ?? null) ? $dataKey['KeyId'] : $this->keyId,
            'encrypted_data_key' => base64_encode(self::bytes($dataKey['CiphertextBlob'] ?? null, 'GenerateDataKey answered no encrypted key.')),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ]);

        return $id;
    }

    public function get(string $reference): KeyBundle
    {
        $entry = KmsKeyStoreEntry::query()->findOrFail($reference);
        $context = self::context($entry->company_id, $entry->id);

        $dataKey = $this->kms->decrypt([
            'KeyId' => $entry->kms_key_id,
            'CiphertextBlob' => self::decoded($entry->encrypted_data_key),
            'EncryptionContext' => $context,
        ]);

        $plaintextKey = self::bytes($dataKey['Plaintext'] ?? null, 'Decrypt answered no plaintext key.');
        $plaintext = openssl_decrypt(self::decoded($entry->ciphertext), self::CIPHER, $plaintextKey, OPENSSL_RAW_DATA, self::decoded($entry->nonce), self::decoded($entry->tag), self::aad($context));
        sodium_memzero($plaintextKey);

        if ($plaintext === false) {
            throw new RuntimeException("The signing key {$reference} could not be decrypted: it was changed or belongs to another entry.");
        }

        $bundle = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        sodium_memzero($plaintext);

        if (! is_array($bundle) || ! is_string($bundle['pkcs12'] ?? null) || ! is_string($bundle['password'] ?? null)) {
            throw new RuntimeException("The signing key {$reference} is not a bundle and its password.");
        }

        return new KeyBundle(base64_decode($bundle['pkcs12'], true) ?: '', $bundle['password']);
    }

    public function delete(string $reference): void
    {
        KmsKeyStoreEntry::query()->whereKey($reference)->delete();
    }

    /**
     * @return array{company_id: string, purpose: string, entry_id: string}
     */
    public static function context(string $companyId, string $entryId): array
    {
        return ['company_id' => $companyId, 'purpose' => self::PURPOSE, 'entry_id' => $entryId];
    }

    /**
     * @param  array<string, string>  $context
     */
    private static function aad(array $context): string
    {
        ksort($context);

        return json_encode($context, JSON_THROW_ON_ERROR);
    }

    private static function bytes(mixed $value, string $error): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException($error);
        }

        return $value;
    }

    private static function decoded(string $base64): string
    {
        return base64_decode($base64, true) ?: throw new RuntimeException('A key store entry is not valid base64.');
    }
}
