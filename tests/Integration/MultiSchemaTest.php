<?php

use Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App1;
use Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App2;

beforeEach(function () {
    // App1 lives on the default "sqlite" connection (set up by the base
    // TestCase); App2 lives on a separate, isolated "sqlite2" connection.
    $this->setUpSecondSchema();

    $this->testUserApp1 = App1\User::create(['email' => 'test@user-app-1.com']);
    $this->testCustomerApp2 = App2\Customer::create(['email' => 'test@customer-app-2.com']);
});

it('resolves role and permission models per context across schemas', function () {
    $roleApp1 = App1\Role::findOrCreate('roleApp1', 'web');
    $roleApp2 = App2\Role::findOrCreate('roleApp2', 'web');
    $permissionApp1 = App1\Permission::findOrCreate('permApp1', 'web');
    $permissionApp2 = App2\Permission::findOrCreate('permApp2', 'web');

    // Each model is backed by its own connection...
    expect($roleApp1->getConnectionName())->toBe('sqlite')
        ->and($roleApp2->getConnectionName())->toBe('sqlite2')
        ->and($permissionApp1->getConnectionName())->toBe('sqlite')
        ->and($permissionApp2->getConnectionName())->toBe('sqlite2');

    // ...and relations resolve to the per-context classes (point 2 seam).
    expect($roleApp1->permissions()->getModel())->toBeInstanceOf(App1\Permission::class)
        ->and($roleApp2->permissions()->getModel())->toBeInstanceOf(App2\Permission::class)
        ->and($this->testUserApp1->roles()->getModel())->toBeInstanceOf(App1\Role::class)
        ->and($this->testCustomerApp2->roles()->getModel())->toBeInstanceOf(App2\Role::class);
});

it('can manage roles and permissions on multiple schemas without switching configuration', function () {
    $roleApp1Name = 'testRoleApp1InWebGuard';
    $roleApp2Name = 'testRoleApp2InWebGuard';
    $permissionApp1Name = 'testPermissionApp1InWebGuard';
    $permissionApp2Name = 'testPermissionApp2InWebGuard';

    expect($this->testUserApp1->hasRole($roleApp1Name))->toBeFalse()
        ->and($this->testCustomerApp2->hasRole($roleApp2Name))->toBeFalse();

    $roleApp1 = App1\Role::findOrCreate($roleApp1Name, 'web');
    $roleApp2 = App2\Role::findOrCreate($roleApp2Name, 'web');

    $permissionApp1 = App1\Permission::findOrCreate($permissionApp1Name, 'web');
    $permissionApp2 = App2\Permission::findOrCreate($permissionApp2Name, 'web');

    $roleApp1->givePermissionTo($permissionApp1Name);
    $roleApp2->givePermissionTo($permissionApp2Name);

    // Reading permissions in succession across the two connections must not
    // leak one schema's cached permission collection into the other.
    // (These name-based checks are exactly what failed before the fix:
    //  Permission::findByName -> ... -> PermissionRegistrar::loadPermissions -> shared cache)
    expect($roleApp1->hasPermissionTo($permissionApp1))->toBeTrue()
        ->and($roleApp2->hasPermissionTo($permissionApp2))->toBeTrue()
        ->and($roleApp1->hasPermissionTo($permissionApp1Name))->toBeTrue()
        ->and($roleApp2->hasPermissionTo($permissionApp2Name))->toBeTrue();

    expect($this->testUserApp1->hasRole($roleApp1))->toBeFalse()
        ->and($this->testCustomerApp2->hasRole($roleApp2))->toBeFalse();

    $this->testUserApp1->assignRole($roleApp1Name);
    expect($this->testUserApp1->hasRole($roleApp1Name))->toBeTrue();

    $this->testCustomerApp2->assignRole($roleApp2Name);
    expect($this->testCustomerApp2->hasRole($roleApp2Name))->toBeTrue();

    $this->testUserApp1->unsetRelation('roles');
    $this->testCustomerApp2->unsetRelation('roles');

    expect($this->testUserApp1->hasRole($roleApp1Name))->toBeTrue()
        ->and($this->testCustomerApp2->hasRole($roleApp2Name))->toBeTrue();

    // App1 user: has its own permission, not App2's.
    expect($this->testUserApp1->hasPermissionTo($permissionApp1Name))->toBeTrue()
        ->and($this->testUserApp1->hasPermissionTo($permissionApp1))->toBeTrue()
        ->and($this->testUserApp1->can($permissionApp1Name))->toBeTrue()
        ->and($this->testUserApp1->checkPermissionTo($permissionApp2Name))->toBeFalse();

    // App2 customer: has its own permission, not App1's.
    expect($this->testCustomerApp2->hasPermissionTo($permissionApp2Name))->toBeTrue()
        ->and($this->testCustomerApp2->hasPermissionTo($permissionApp2))->toBeTrue()
        ->and($this->testCustomerApp2->checkPermissionTo($permissionApp1Name))->toBeFalse();
});
