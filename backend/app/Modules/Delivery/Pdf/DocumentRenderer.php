<?php

namespace App\Modules\Delivery\Pdf;

/**
 * Document generation: draws the graphic representation of an invoice as a PDF. The local adapter
 * is DompdfDocumentRenderer (pure PHP, no browser and no extra service); another engine only needs
 * another implementation bound in DeliveryServiceProvider.
 */
interface DocumentRenderer
{
    public function render(InvoiceRepresentation $representation): RenderedDocument;
}
