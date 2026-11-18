<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Dian\Transmission\DianPackage;
use RuntimeException;

/**
 * The single zip one email carries (annex 1.9, section 9.1): the AttachedDocument and, inside the
 * same zip, the PDF of the graphic representation. Named z{NIT}{ppp}{aa}{dddddddd}.zip with the
 * consecutive of its AttachedDocument (6.5.8), and never larger than 2 MB.
 */
final class DeliveryPackages
{
    public function __construct(
        private readonly DocumentFiles $files,
        private readonly DianPackage $package,
    ) {}

    public function build(Document $document): DocumentFile
    {
        $attached = $this->files->find($document, DocumentFileKind::AttachedDocument)
            ?? throw new RuntimeException('The AttachedDocument is missing.');
        $pdf = $this->files->find($document, DocumentFileKind::Pdf)
            ?? throw new RuntimeException('The PDF is missing.');

        $zip = $this->package->archive([
            $attached->file_name => $this->files->contents($attached),
            $pdf->file_name => $this->files->contents($pdf),
        ]);

        $limit = (int) config('delivery.max_package_bytes');

        if (strlen($zip) > $limit) {
            throw new RuntimeException(sprintf('The zip has %d bytes; one delivery may carry up to %d (annex 1.9, section 9.1).', strlen($zip), $limit));
        }

        return $this->files->store($document, DocumentFileKind::Package, self::fileName($document), 'application/zip', $zip);
    }

    public static function fileName(Document $document): string
    {
        if ($document->delivery_file_base === null) {
            throw new RuntimeException('The AttachedDocument of this document has no name yet.');
        }

        return 'z'.$document->delivery_file_base.'.zip';
    }
}
