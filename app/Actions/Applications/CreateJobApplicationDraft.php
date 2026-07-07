<?php

namespace App\Actions\Applications;

use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateJobApplicationDraft
{
    public function execute(
        GeneratedDocumentVersion $version,
        User $actor,
    ): JobApplication {
        return DB::transaction(function () use ($version, $actor): JobApplication {
            $version = GeneratedDocumentVersion::query()
                ->with([
                    'generatedDocument.profile',
                    'sourceResumeVersion.resume',
                ])
                ->lockForUpdate()
                ->findOrFail($version->getKey());

            $document = $version->generatedDocument;

            if ((int) $document->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this generated document.');
            }

            $this->ensureVersionCanCreateApplication($version);

            if ($document->job_application_id !== null) {
                $application = JobApplication::query()
                    ->whereKey($document->job_application_id)
                    ->where('profile_id', $document->profile_id)
                    ->lockForUpdate()
                    ->first();

                if ($application === null) {
                    throw ValidationException::withMessages([
                        'generated_document' => 'The linked job application is not valid for this profile.',
                    ]);
                }

                if ((int) $application->generated_document_version_id !== (int) $version->getKey()) {
                    throw ValidationException::withMessages([
                        'generated_document_version' => 'The generated document is already linked to an application using another approved version.',
                    ]);
                }

                return $application->fresh($this->applicationRelations());
            }

            $posting = JobPosting::query()
                ->with('company')
                ->lockForUpdate()
                ->findOrFail($document->job_posting_id);

            if ((int) $posting->profile_id !== (int) $document->profile_id) {
                throw ValidationException::withMessages([
                    'job_posting' => 'The job posting and generated document do not belong to the same profile.',
                ]);
            }

            $sourceResumeVersion = ResumeVersion::query()
                ->with('resume')
                ->lockForUpdate()
                ->findOrFail($version->source_resume_version_id);

            if ((int) $sourceResumeVersion->resume->profile_id !== (int) $document->profile_id) {
                throw ValidationException::withMessages([
                    'source_resume_version' => 'The source resume and generated document do not belong to the same profile.',
                ]);
            }

            $application = JobApplication::create([
                'profile_id' => $document->profile_id,
                'job_posting_id' => $posting->getKey(),
                'resume_version_id' => $sourceResumeVersion->getKey(),
                'generated_document_version_id' => $version->getKey(),
                'job_title' => $posting->title,
                'company_name' => $posting->company_name ?: $posting->company?->name,
                'status' => 'draft',
                'notes' => 'Prepared from approved generated document version #'.$version->getKey().'.',
            ]);

            $application->statusHistory()->create([
                'from_status' => null,
                'status' => 'draft',
                'changed_by' => $actor->getKey(),
                'changed_at' => now(),
                'notes' => 'Draft created from approved generated document version #'.$version->getKey().'.',
            ]);

            $document->forceFill([
                'job_application_id' => $application->getKey(),
            ])->save();

            return $application->fresh($this->applicationRelations());
        });
    }

    private function ensureVersionCanCreateApplication(
        GeneratedDocumentVersion $version,
    ): void {
        $document = $version->generatedDocument;

        if ($version->review_status !== 'approved') {
            throw ValidationException::withMessages([
                'generated_document_version' => 'Only approved document versions can create an application draft.',
            ]);
        }

        if ($version->contains_unverified_claims) {
            throw ValidationException::withMessages([
                'generated_document_version' => 'A version containing unverified claims cannot create an application draft.',
            ]);
        }

        if ($version->generator_key === DeterministicTargetedResumeDraftBuilder::KEY) {
            throw ValidationException::withMessages([
                'generated_document_version' => 'A technical matching review draft must be finalized before it can be used for an application.',
            ]);
        }

        if ($document->document_type !== 'targeted_resume') {
            throw ValidationException::withMessages([
                'generated_document' => 'Only targeted resume documents can create an application draft.',
            ]);
        }

        if ($document->status !== 'ready') {
            throw ValidationException::withMessages([
                'generated_document' => 'The generated document must be ready before creating an application draft.',
            ]);
        }

        if ($document->job_posting_id === null) {
            throw ValidationException::withMessages([
                'job_posting' => 'The generated document is not linked to a job posting.',
            ]);
        }

        if ($version->source_resume_version_id === null) {
            throw ValidationException::withMessages([
                'source_resume_version' => 'The generated document version has no source resume.',
            ]);
        }
    }

    private function applicationRelations(): array
    {
        return [
            'jobPosting',
            'resumeVersion',
            'generatedDocumentVersion',
            'statusHistory.changedBy',
            'generatedDocuments',
        ];
    }
}
