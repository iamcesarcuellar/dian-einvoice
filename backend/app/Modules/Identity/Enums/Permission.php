<?php

namespace App\Modules\Identity\Enums;

/**
 * What a role may change inside a company. Every member, viewers included, can read.
 */
enum Permission: string
{
    /** Contacts and items. */
    case ManageCatalog = 'manage-catalog';

    /** Invoices and notes: drafts, deletion of drafts and issuing. */
    case ManageDocuments = 'manage-documents';

    /** Taxes, withholdings, numbering and the company profile. */
    case ManageSettings = 'manage-settings';

    /** Record payments from customers and apply them to their invoices. */
    case RecordPayments = 'record-payments';

    /** Void a recorded payment, which gives its invoices their balance back. */
    case VoidPayments = 'void-payments';

    /** The members of the company, their roles and the invitations to join it. */
    case ManageTeam = 'manage-team';
}
