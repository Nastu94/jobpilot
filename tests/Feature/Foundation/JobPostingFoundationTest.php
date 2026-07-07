<?php

namespace Tests\Feature\Foundation;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPostingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_job_postings_with_company_metadata(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $company = Company::create([
            'name' => 'Example Company',
            'website_url' => 'https://example.com',
            'headquarters_location' => 'Roma',
            'country_code' => 'IT',
        ]);

        $older = JobPosting::create([
            'profile_id' => $profile->id,
            'company_id' => $company->id,
            'title' => 'Junior PHP Developer',
            'company_name' => 'Example Company S.r.l.',
            'source' => 'manual',
            'source_url' => 'https://example.com/jobs/1',
            'location' => 'Roma',
            'country_code' => 'IT',
            'remote_type' => 'hybrid',
            'employment_type' => 'full_time',
            'seniority' => 'junior',
            'salary_min' => 26000,
            'salary_max' => 32000,
            'currency' => 'EUR',
            'status' => 'active',
            'processing_status' => 'completed',
            'description' => 'Structured job description.',
            'raw_content' => 'Original job advertisement content.',
            'content_hash' => str_repeat('b', 64),
            'published_at' => '2026-06-01 09:00:00',
            'captured_at' => '2026-06-02 10:00:00',
        ]);

        $newer = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
            'company_name' => 'Anonymous Company',
            'source' => 'job_board',
            'external_id' => 'JOB-002',
            'published_at' => '2026-07-01 12:00:00',
        ]);

        $postings = $profile->fresh()->jobPostings;

        $this->assertSame([$newer->id, $older->id], $postings->pluck('id')->all());
        $this->assertTrue($older->fresh()->company->is($company));
        $this->assertTrue($older->fresh()->profile->is($profile));
        $this->assertSame(26000, $older->fresh()->salary_min);
        $this->assertSame('2026-06-01 09:00:00', $older->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('Example Company S.r.l.', $older->fresh()->company_name);
    }

    public function test_deleting_company_preserves_job_posting_and_raw_company_name(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $company = Company::create(['name' => 'Example Company']);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'company_id' => $company->id,
            'title' => 'Backend Developer',
            'company_name' => 'Example Company',
        ]);

        $company->delete();

        $posting->refresh();

        $this->assertNull($posting->company_id);
        $this->assertSame('Example Company', $posting->company_name);
        $this->assertDatabaseHas('job_postings', ['id' => $posting->id]);
    }

    public function test_deleting_profile_removes_job_postings(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('job_postings', ['id' => $posting->id]);
    }
}
