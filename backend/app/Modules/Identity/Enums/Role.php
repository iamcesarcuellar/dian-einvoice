<?php

namespace App\Modules\Identity\Enums;

/**
 * Role of a user inside one company. A user can hold a different role in each company.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Viewers only read; members manage contacts, items and documents and record payments; owners
     * and admins manage everything, voiding payments included.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => [Permission::ManageCatalog, Permission::ManageDocuments, Permission::ManageSettings, Permission::RecordPayments, Permission::VoidPayments],
            self::Member => [Permission::ManageCatalog, Permission::ManageDocuments, Permission::RecordPayments],
            self::Viewer => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
