<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Local adapter of DocumentStorage: the private "documents" disk (storage/app/documents), which is
 * never served over HTTP. Files reach clients only through authorized API endpoints.
 */
final class LocalDocumentStorage implements DocumentStorage
{
    public const string DISK = 'documents';

    public function put(string $path, string $contents): StoredDocument
    {
        if (! $this->disk()->put($path, $contents)) {
            throw new RuntimeException("The document could not be written to {$path}.");
        }

        return new StoredDocument($path, hash('sha256', $contents), strlen($contents));
    }

    public function get(string $path): string
    {
        return $this->disk()->get($path)
            ?? throw new RuntimeException("The document {$path} does not exist.");
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->disk()->deleteDirectory($prefix);
    }

    public function purge(): void
    {
        $disk = $this->disk();

        foreach ($disk->directories() as $directory) {
            $disk->deleteDirectory($directory);
        }

        foreach ($disk->files() as $file) {
            $disk->delete($file);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
