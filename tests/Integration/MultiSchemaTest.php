<?php

use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App1;
use Spatie\Permission\Tests\TestSupport\TestModels\MultiSchemas\App2;

/**
 * Provision the second (tenant) schema and create one model per schema.
 * App1 lives on the default "sqlite" connection (set up by the base TestCase);
 * App2 lives on a separate, isolated "sqlite2" connection.
 */
function bootMultiSchema(bool $tenantTeams = false): void
{
    $testCase = test();

    $testCase->setUpSecondSchema('sqlite2', teamsEnabled: $tenantTeams);

    if ($tenantTeams) {
        // Per-connection configuration: only the tenant connection uses teams.
        config()->set('permission.connections.sqlite2.teams', true);
    }

    $testCase->testUserApp1 = App1\User::create(['email' => 'test@user-app-1.com']);
    $testCase->testCustomerApp2 = App2\Customer::create(['email' => 'test@customer-app-2.com']);
}

describe('isolated schemas (uniform configuration)', function () {
    beforeEach(fn () => bootMultiSchema());

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
});

describe('per-connection feature configuration', function () {
    beforeEach(fn () => bootMultiSchema(tenantTeams: true));

    it('supports a teamless central connection alongside a team-enabled tenant connection', function () {
        setPermissionsTeamId(1);

        $registrar = app(PermissionRegistrar::class);

        // Teams resolve per connection...
        expect($registrar->teamsEnabledFor('sqlite'))->toBeFalse()
            ->and($registrar->teamsEnabledFor('sqlite2'))->toBeTrue();
        // ...and each model reflects its own connection's setting.
        expect($this->testUserApp1->permissionsTeamsEnabled())->toBeFalse()
            ->and($this->testCustomerApp2->permissionsTeamsEnabled())->toBeTrue();

        // Central (teams disabled): role created and assigned without team scoping.
        $centralRole = App1\Role::findOrCreate('centralRole', 'web');
        $this->testUserApp1->assignRole('centralRole');

        // Tenant (teams enabled): in the SAME request, role is team-scoped.
        $tenantRole = App2\Role::findOrCreate('tenantRole', 'web');
        $this->testCustomerApp2->assignRole('tenantRole');

        // The tenant role carries the active team id; the central one does not.
        expect($tenantRole->getAttribute('team_test_id'))->toBe(1)
            ->and($centralRole->getAttribute('team_test_id'))->toBeNull();

        expect($this->testUserApp1->hasRole('centralRole'))->toBeTrue()
            ->and($this->testCustomerApp2->hasRole('tenantRole'))->toBeTrue();

        // Switching the active team hides the tenant role (team scoping is active)...
        setPermissionsTeamId(2);
        $this->testCustomerApp2->unsetRelation('roles');
        expect($this->testCustomerApp2->hasRole('tenantRole'))->toBeFalse();

        // ...while the central role is unaffected by the team id (teams disabled there).
        $this->testUserApp1->unsetRelation('roles');
        expect($this->testUserApp1->hasRole('centralRole'))->toBeTrue();
    });
});
