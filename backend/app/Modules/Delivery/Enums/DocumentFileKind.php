<?php

namespace App\Modules\Delivery\Enums;

/**
 * Files delivered to the customer of an accepted document.
 */
enum DocumentFileKind: string
{
    /** The signed AttachedDocument with the signed invoice and the ApplicationResponse inside. */
    case AttachedDocument = 'attached_document';

    /** The graphic representation of the invoice. */
    case Pdf = 'pdf';

    /** The zip the email carries: the AttachedDocument and the PDF. */
    case Package = 'package';
}
