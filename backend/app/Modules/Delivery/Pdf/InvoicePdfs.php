<?php

namespace App\Modules\Delivery\Pdf;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Delivery\Services\DocumentFiles;

/**
 * The second delivery step: draws the graphic representation of an accepted invoice and stores it.
 * Inside the zip it is named after the XML of the invoice (fv{...}.pdf), since annex 1.9 leaves the
 * names of the delivered files to the parties (section 9.1).
 */
final class InvoicePdfs
{
    public function __construct(
        private readonly InvoiceRepresentationBuilder $builder,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentFiles $files,
    ) {}

    public function generate(Document $document): DocumentFile
    {
        $rendered = $this->renderer->render($this->builder->build($document));

        if (! str_starts_with($rendered->contents, '%PDF-') || $rendered->pages < 1) {
            throw new \RuntimeException('The renderer did not produce a PDF.');
        }

        return $this->files->store($document, DocumentFileKind::Pdf, self::fileName($document), 'application/pdf', $rendered->contents, $rendered->pages);
    }

    public static function fileName(Document $document): string
    {
        $xml = (string) $document->dian_xml_file_name;

        return $xml !== '' ? preg_replace('/\.xml$/', '.pdf', $xml) ?? $xml.'.pdf' : $document->full_number.'.pdf';
    }
}
