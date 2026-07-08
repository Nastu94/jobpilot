<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationFollowUpQueue;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildJobApplicationFollowUpQueueDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_options_use_current_time_and_safe_defaults(): void
    {
        $now = CarbonImmutable::parse('2026-07-08 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $owner = User::factory()->create();
            $profile = Profile::create(['user_id' => $owner->id]);
            JobApplication::create([
                'profile_id' => $profile->id,
                'job_title' => 'Backend Developer',
                'company_name' => 'Acme',
                'status' => 'screening',
                'next_action_at' => '2026-07-09 09:00:00',
            ]);

            $queue = app(BuildJobApplicationFollowUpQueue::class)->execute(
                $profile,
                $owner,
            );

            $this->assertSame($now->toISOString(), $queue['reference_at']);
            $this->assertSame(7, $queue['upcoming_days']);
            $this->assertSame(25, $queue['limit_per_bucket']);
            $this->assertSame(1, $queue['summary']['upcoming']['total']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
