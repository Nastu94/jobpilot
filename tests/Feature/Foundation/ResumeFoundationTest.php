<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_resumes_with_ordered_versions(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $secondaryResume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'General resume',
        ]);

        $primaryResume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Primary resume',
            'is_primary' => true,
        ]);

        $olderVersion = ResumeVersion::create([
            'resume_id' => $primaryResume->id,
            'version_number' => 1,
            'original_filename' => 'resume-v1.pdf',
            'storage_path' => 'resumes/resume-v1.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120000,
            'checksum_sha256' => str_repeat('a', 64),
            'processing_status' => 'completed',
            'extracted_text' => 'Version one text.',
        ]);

        $newerVersion = ResumeVersion::create([
            'resume_id' => $primaryResume->id,
            'version_number' => 2,
            'source' => 'generated',
            'original_filename' => 'resume-v2.docx',
            'storage_path' => 'resumes/resume-v2.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => 180000,
            'processing_status' => 'pending',
        ]);

        $resumes = $profile->fresh()->resumes;
        $versions = $primaryResume->fresh()->versions;

        $this->assertSame([$primaryResume->id, $secondaryResume->id], $resumes->pluck('id')->all());
        $this->assertSame([$newerVersion->id, $olderVersion->id], $versions->pluck('id')->all());
        $this->assertTrue($primaryResume->fresh()->is_primary);
        $this->assertTrue($newerVersion->fresh()->resume->is($primaryResume));
        $this->assertSame(2, $newerVersion->fresh()->version_number);
        $this->assertSame(180000, $newerVersion->fresh()->file_size);
    }

    public function test_resume_version_number_is_unique_within_resume(): void
    {
        $this->expectException(QueryException::class);

        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Primary resume',
        ]);

        $version = [
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'resume.pdf',
            'storage_path' => 'resumes/resume.pdf',
        ];

        ResumeVersion::create($version);
        ResumeVersion::create($version);
    }

    public function test_deleting_profile_removes_resumes_and_versions(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Primary resume',
        ]);
        $version = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'resume.pdf',
            'storage_path' => 'resumes/resume.pdf',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
        $this->assertDatabaseMissing('resume_versions', ['id' => $version->id]);
    }
}
