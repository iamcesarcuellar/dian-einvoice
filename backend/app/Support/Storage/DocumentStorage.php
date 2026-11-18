<?php

namespace App\Support\Storage;

/**
 * Private storage for the files of electronic documents (XML now; signed XML, PDF and
 * AttachedDocument in later stages). Paths are relative and start with the company id. The local
 * adapter writes to a private disk; Infrastructure wires a real object store in its place.
 */
interface DocumentStorage
{
    /** Writes the contents, replacing any file at the path. */
    public function put(string $path, string $contents): StoredDocument;

    public function get(string $path): string;

    public function exists(string $path): bool;

    /** Removes every file under the prefix, such as everything of one company. */
    public function deletePrefix(string $prefix): void;
}
