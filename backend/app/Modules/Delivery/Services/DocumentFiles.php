<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Models\DocumentFile;
use App\Support\Storage\DocumentStorage;

/**
 * The files delivered to the customer of a document, through the private document storage: one
 * per kind, under {company}/{type}/delivery/{number}/, recorded with their SHA-256 and size.
 */
final class DocumentFiles
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /** Writes the file of a kind, replacing the previous one, and records it for the current run. */
    public function store(Document $document, DocumentFileKind $kind, string $fileName, string $mimeType, string $contents, ?int $pages = null): DocumentFile
    {
        $previous = $this->find($document, $kind);
        $stored = $this->storage->put($this->path($document, $fileName), $contents);

        $file = DocumentFile::query()->updateOrCreate(
            ['document_id' => $document->getKey(), 'kind' => $kind],
            [
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'path' => $stored->path,
                'sha256' => $stored->sha256,
                'size' => $stored->size,
                'pages' => $pages,
                'run' => $document->delivery_run,
            ],
        );

        if ($previous !== null && $previous->path !== $stored->path) {
            $this->storage->delete($previous->path);
        }

        return $file;
    }

    public function find(Document $document, DocumentFileKind $kind): ?DocumentFile
    {
        return DocumentFile::query()
            ->where('document_id', $document->getKey())
            ->where('kind', $kind)
            ->first();
    }

    public function contents(DocumentFile $file): string
    {
        return $this->storage->get($file->path);
    }

    public function path(Document $document, string $fileName): string
    {
        return sprintf('%s/%s/delivery/%s/%s', $document->company_id, $document->type->value, $document->full_number, $fileName);
    }
}
