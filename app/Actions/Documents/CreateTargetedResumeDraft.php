<?php

namespace App\Actions\Documents;

use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobPosting;
use App\Models\MatchAnalysis;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTargetedResumeDraft
{
    public function __construct(
        private readonly DeterministicTargetedResumeDraftBuilder $builder,
    ) {
    }

    public function execute(
        MatchAnalysis $analysis,
        ResumeVersion $sourceResumeVersion,
        User $actor,
    ): GeneratedDocumentVersion {
        return DB::transaction(function () use (
            $analysis,
            $sourceResumeVersion,
            $actor,
        ): GeneratedDocumentVersion {
            $analysis = MatchAnalysis::query()
                ->with(['profile', 'jobPosting', 'factors.evidences'])
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());

            if ((int) $analysis->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this match analysis.');
            }

            if (! in_array($analysis->status, ['completed', 'partial_coverage'], true)) {
                throw ValidationException::withMessages([
                    'match_analysis' => 'Only completed analyses with scorable requirements can create a draft.',
                ]);
            }

            $jobPosting = JobPosting::query()
                ->lockForUpdate()
                ->findOrFail($analysis->job_posting_id);

            if ((int) $jobPosting->profile_id !== (int) $analysis->profile_id) {
                throw ValidationException::withMessages([
                    'match_analysis' => 'The analysis and job posting do not belong to the same profile.',
                ]);
            }

            $sourceResumeVersion = ResumeVersion::query()
                ->with('resume')
                ->lockForUpdate()
                ->findOrFail($sourceResumeVersion->getKey());

            if ((int) $sourceResumeVersion->resume->profile_id !== (int) $analysis->profile_id) {
                throw ValidationException::withMessages([
                    'source_resume_version' => 'The source resume does not belong to the analysis profile.',
                ]);
            }

            if (
                $sourceResumeVersion->processing_status !== 'completed'
                || trim((string) $sourceResumeVersion->extracted_text) === ''
            ) {
                throw ValidationException::withMessages([
                    'source_resume_version' => 'The source resume must have completed text extraction.',
                ]);
            }

            $draft = $this->builder->build($sourceResumeVersion, $analysis);
            $document = GeneratedDocument::query()
                ->where('profile_id', $analysis->profile_id)
                ->where('job_posting_id', $jobPosting->getKey())
                ->where('document_type', 'targeted_resume')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                $document = GeneratedDocument::create([
                    'profile_id' => $analysis->profile_id,
                    'job_posting_id' => $jobPosting->getKey(),
                    'document_type' => 'targeted_resume',
                    'name' => $this->documentName($jobPosting),
                    'status' => 'draft',
                    'notes' => 'Deterministic review draft. Manual review is required before export.',
                ]);
            }

            $existingVersion = $document->versions()
                ->where('source_resume_version_id', $sourceResumeVersion->getKey())
                ->where('match_analysis_id', $analysis->getKey())
                ->where('generator_key', DeterministicTargetedResumeDraftBuilder::KEY)
                ->where('generator_version', DeterministicTargetedResumeDraftBuilder::VERSION)
                ->first();

            if ($existingVersion !== null) {
                return $existingVersion->fresh([
                    'generatedDocument',
                    'sourceResumeVersion',
                    'matchAnalysis',
                ]);
            }

            $nextVersionNumber = ((int) $document->versions()->max('version_number')) + 1;
            $document->forceFill(['status' => 'draft'])->save();

            $version = $document->versions()->create([
                'source_resume_version_id' => $sourceResumeVersion->getKey(),
                'match_analysis_id' => $analysis->getKey(),
                'version_number' => $nextVersionNumber,
                'generation_method' => 'template',
                'generator_key' => DeterministicTargetedResumeDraftBuilder::KEY,
                'generator_version' => DeterministicTargetedResumeDraftBuilder::VERSION,
                'input_hash' => $draft['input_hash'],
                'content_format' => 'markdown',
                'content' => $draft['content'],
                'review_status' => 'pending',
                'contains_unverified_claims' => false,
                'change_summary' => $draft['change_summary'],
            ]);

            return $version->fresh([
                'generatedDocument',
                'sourceResumeVersion',
                'matchAnalysis',
            ]);
        });
    }

    private function documentName(JobPosting $jobPosting): string
    {
        $company = trim((string) $jobPosting->company_name);

        return $company === ''
            ? 'Targeted CV - '.$jobPosting->title
            : 'Targeted CV - '.$jobPosting->title.' at '.$company;
    }
}
