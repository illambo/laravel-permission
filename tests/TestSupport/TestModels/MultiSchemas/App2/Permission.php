<?php

namespace Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App2;

class Permission extends \Spatie\Permission\Models\Permission
{
    protected $connection = 'sqlite2';

    public function getRoleClass(): string
    {
        return Role::class;
    }
}
