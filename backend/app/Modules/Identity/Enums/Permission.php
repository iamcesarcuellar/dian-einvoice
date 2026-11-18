<?php

namespace App\Modules\Identity\Enums;

/**
 * What a role may change inside a company. Every member, viewers included, can read.
 */
enum Permission: string
{
    /** Contacts and items. */
    case ManageCatalog = 'manage-catalog';

    /** Invoices and, from stage 8, notes: drafts, deletion of drafts and issuing. */
    case ManageDocuments = 'manage-documents';

    /** Taxes, withholdings, numbering and the company profile. */
    case ManageSettings = 'manage-settings';
}
