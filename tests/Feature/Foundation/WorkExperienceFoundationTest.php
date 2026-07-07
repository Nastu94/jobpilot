<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\User;
use App\Models\WorkExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkExperienceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_work_experiences_and_ordered_tasks(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $experience = WorkExperience::create([
            'profile_id' => $profile->id,
            'company_name' => 'Example Company',
            'job_title' => 'Logistics Employee',
            'start_date' => '2024-01-15',
            'end_date' => null,
            'is_current' => true,
        ]);

        $experience->tasks()->create([
            'description' => 'Second task',
            'position' => 2,
        ]);

        $experience->tasks()->create([
            'description' => 'First task',
            'position' => 1,
        ]);

        $experience = $experience->fresh();

        $this->assertTrue($profile->fresh()->workExperiences->contains($experience));
        $this->assertTrue($experience->profile->is($profile));
        $this->assertTrue($experience->is_current);
        $this->assertSame('2024-01-15', $experience->start_date->toDateString());
        $this->assertSame(
            ['First task', 'Second task'],
            $experience->tasks->pluck('description')->all(),
        );
    }

    public function test_deleting_profile_removes_its_experiences_and_tasks(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $experience = WorkExperience::create([
            'profile_id' => $profile->id,
            'company_name' => 'Example Company',
            'job_title' => 'Employee',
            'start_date' => '2023-05-01',
        ]);

        $task = $experience->tasks()->create([
            'description' => 'Example task',
            'position' => 1,
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('work_experiences', ['id' => $experience->id]);
        $this->assertDatabaseMissing('work_experience_tasks', ['id' => $task->id]);
    }
}
