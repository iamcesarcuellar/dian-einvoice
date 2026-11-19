<?php

namespace App\Support\Keys;

use SensitiveParameter;

/**
 * Local adapter of KeyStore: encrypted rows in the key_store_entries table, scoped to the
 * current company like every tenant record.
 */
final class LocalKeyStore implements KeyStore
{
    public function put(string $companyId, string $pkcs12, #[SensitiveParameter] string $password): string
    {
        return KeyStoreEntry::create([
            'company_id' => $companyId,
            'payload' => ['pkcs12' => base64_encode($pkcs12), 'password' => $password],
        ])->id;
    }

    public function get(string $reference): KeyBundle
    {
        $payload = KeyStoreEntry::query()->findOrFail($reference)->payload;

        return new KeyBundle(base64_decode($payload['pkcs12'], true) ?: '', $payload['password']);
    }

    public function delete(string $reference): void
    {
        KeyStoreEntry::query()->whereKey($reference)->delete();
    }
}
