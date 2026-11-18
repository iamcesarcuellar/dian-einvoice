<?php

namespace App\Modules\Developers\Enums;

use App\Modules\Identity\Enums\Permission;

/**
 * What an API key may do. Both read every business resource; read_write also writes contacts,
 * items, invoices, notes and payments and runs their actions. No key manages users, keys,
 * webhooks, certificates, DIAN settings or the simulator: that belongs to the web app session.
 */
enum ApiKeyLevel: string
{
    case ReadOnly = 'read_only';
    case ReadWrite = 'read_write';

    public function allows(Permission $permission): bool
    {
        return $this === self::ReadWrite && in_array($permission, [
            Permission::ManageCatalog,
            Permission::ManageDocuments,
            Permission::RecordPayments,
            Permission::VoidPayments,
        ], true);
    }
}
