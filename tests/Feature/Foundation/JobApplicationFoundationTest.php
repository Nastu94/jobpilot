<?php

namespace Tests\Feature\Foundation;

use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApplicationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_applications_with_status_history(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $version = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
            'company_name' => 'Acme',
        ]);

        $older = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'PHP Developer',
            'applied_at' => '2026-06-10 09:00:00',
        ]);
        $newer = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $version->id,
            'job_title' => 'Laravel Developer',
            'company_name' => 'Acme',
            'status' => 'interview',
            'applied_at' => '2026-07-01 10:00:00',
            'next_action_at' => '2026-07-10 15:30:00',
        ]);

        $later = JobApplicationStatusHistory::create([
            'job_application_id' => $newer->id,
            'status' => 'interview',
            'changed_at' => '2026-07-05 12:00:00',
        ]);
        $earlier = JobApplicationStatusHistory::create([
            'job_application_id' => $newer->id,
            'status' => 'applied',
            'changed_at' => '2026-07-01 10:00:00',
        ]);

        $this->assertSame([$newer->id, $older->id], $profile->fresh()->jobApplications->pluck('id')->all());
        $this->assertSame([$earlier->id, $later->id], $newer->fresh()->statusHistory->pluck('id')->all());
        $this->assertTrue($newer->fresh()->jobPosting->is($posting));
        $this->assertTrue($newer->fresh()->resumeVersion->is($version));
        $this->assertSame('2026-07-10 15:30:00', $newer->fresh()->next_action_at->format('Y-m-d H:i:s'));
    }

    public function test_deleting_dependencies_preserves_application_snapshots(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $version = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $version->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
        ]);

        $posting->delete();
        $version->delete();
        $application->refresh();

        $this->assertNull($application->job_posting_id);
        $this->assertNull($application->resume_version_id);
        $this->assertSame('Backend Developer', $application->job_title);
        $this->assertSame('Acme', $application->company_name);
    }

    public function test_deleting_profile_removes_applications_and_history(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
        ]);
        $history = JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'status' => 'draft',
            'changed_at' => '2026-07-01 09:00:00',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('job_applications', ['id' => $application->id]);
        $this->assertDatabaseMissing('job_application_status_histories', ['id' => $history->id]);
    }
}
