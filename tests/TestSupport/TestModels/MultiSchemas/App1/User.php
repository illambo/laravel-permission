<?php

namespace Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App1;

use Spatie\Permission\Tests\TestSupport\TestModels\UserWithoutHasRoles;
use Spatie\Permission\Traits\HasRoles;

class User extends UserWithoutHasRoles
{
    use HasRoles;

    protected string $guard_name = 'web';

    protected $connection = 'sqlite';

    public function getRoleClass(): string
    {
        return Role::class;
    }

    public function getPermissionClass(): string
    {
        return Permission::class;
    }
}
