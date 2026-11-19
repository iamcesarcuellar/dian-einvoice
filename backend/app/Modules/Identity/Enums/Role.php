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
     * and admins manage everything, voiding payments and the team included. Only owners grant the
     * owner role or change and remove an owner (see TeamMembers and TeamInvitations).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => [Permission::ManageCatalog, Permission::ManageDocuments, Permission::ManageSettings, Permission::RecordPayments, Permission::VoidPayments, Permission::ManageTeam],
            self::Member => [Permission::ManageCatalog, Permission::ManageDocuments, Permission::RecordPayments],
            self::Viewer => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Position in the team list: owners first, viewers last. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 0,
            self::Admin => 1,
            self::Member => 2,
            self::Viewer => 3,
        };
    }
}
