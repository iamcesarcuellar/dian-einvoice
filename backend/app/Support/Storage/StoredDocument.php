<?php

namespace App\Support\Storage;

/**
 * Where a file was stored, with the fingerprint and size of exactly the bytes written.
 */
final readonly class StoredDocument
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $size,
    ) {}
}
