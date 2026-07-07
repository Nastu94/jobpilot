<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_assigned_and_query_a_role(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'candidate']);

        $user->assignRole($role);

        $this->assertTrue($user->hasRole('candidate'));
        $this->assertTrue($user->roles->contains($role));
    }
}
