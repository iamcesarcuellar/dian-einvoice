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
     * Owners and admins may edit the company profile.
     */
    public function canManageCompany(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
