<?php

namespace App\Modules\Identity\Enums;

/**
 * What a role may change inside a company. Every member, viewers included, can read.
 */
enum Permission: string
{
    /** Contacts and items. */
    case ManageCatalog = 'manage-catalog';

    /** Taxes, withholdings, numbering and the company profile. */
    case ManageSettings = 'manage-settings';
}
