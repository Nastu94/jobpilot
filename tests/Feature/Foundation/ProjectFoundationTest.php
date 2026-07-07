<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_projects(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $older = Project::create([
            'profile_id' => $profile->id,
            'name' => 'Older project',
            'role' => 'Developer',
            'description' => 'First project description.',
            'url' => 'https://example.com/older-project',
            'repository_url' => 'https://example.com/older-repository',
            'start_date' => '2021-03-01',
            'end_date' => '2021-09-30',
        ]);

        $newer = Project::create([
            'profile_id' => $profile->id,
            'name' => 'Current project',
            'role' => 'Lead developer',
            'start_date' => '2024-02-15',
            'is_current' => true,
        ]);

        $projects = $profile->fresh()->projects;

        $this->assertSame([$newer->id, $older->id], $projects->pluck('id')->all());
        $this->assertTrue($newer->fresh()->profile->is($profile));
        $this->assertTrue($newer->fresh()->is_current);
        $this->assertSame('2024-02-15', $newer->fresh()->start_date->toDateString());
        $this->assertSame('2021-09-30', $older->fresh()->end_date->toDateString());
    }

    public function test_deleting_profile_removes_projects(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $project = Project::create([
            'profile_id' => $profile->id,
            'name' => 'Example project',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }
}
