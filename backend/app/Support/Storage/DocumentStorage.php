<?php

namespace App\Support\Storage;

/**
 * Private storage for the files of electronic documents: the signed XML, the DIAN simulator's
 * ApplicationResponse, the AttachedDocument and the PDF. Paths are relative and start with the
 * company id. The local adapter writes to a private disk; in the emulated cloud and in prod,
 * S3DocumentStorage writes to the documents bucket in its place.
 */
interface DocumentStorage
{
    /** Writes the contents, replacing any file at the path. */
    public function put(string $path, string $contents): StoredDocument;

    public function get(string $path): string;

    public function exists(string $path): bool;

    /** Removes one file; nothing happens when it does not exist. */
    public function delete(string $path): void;

    /** Removes every file under the prefix, such as everything of one company. */
    public function deletePrefix(string $prefix): void;

    /**
     * Removes every stored file. Only the demo seeder calls it, right after the database is
     * recreated, when no stored file belongs to any document any more.
     */
    public function purge(): void;
}
