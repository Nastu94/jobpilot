<?php

namespace App\Actions\Applications;

use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationDocumentVersionHistory;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SelectJobApplicationDocumentVersion
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
    ) {
    }

    public function execute(
        JobApplication $application,
        GeneratedDocumentVersion $version,
        User $actor,
        array $input = [],
    ): JobApplication {
        return DB::transaction(function () use ($application, $version, $actor, $input): JobApplication {
            $application = JobApplication::query()
                ->with([
                    'profile',
                    'resumeVersion.resume',
                    'generatedDocumentVersion.generatedDocument',
                    'generatedDocumentVersion.sourceResumeVersion.resume',
                ])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $selection = $this->validatedSelection($input);

            if ($application->status !== 'draft') {
                throw ValidationException::withMessages([
                    'job_application' => 'Only a draft application can change its selected document version.',
                ]);
            }

            $previousVersion = $application->generatedDocumentVersion;

            if ($previousVersion === null || $previousVersion->generatedDocument === null) {
                throw ValidationException::withMessages([
                    'generated_document_version' => 'The application has no valid current document version to replace.',
                ]);
            }

            if ((int) $previousVersion->getKey() === (int) $version->getKey()) {
                return $application->fresh($this->applicationRelations());
            }

            $version = GeneratedDocumentVersion::query()
                ->with([
                    'generatedDocument.profile',
                    'sourceResumeVersion.resume',
                ])
                ->lockForUpdate()
                ->findOrFail($version->getKey());

            if ((int) $version->generated_document_id !== (int) $previousVersion->generated_document_id) {
                throw ValidationException::withMessages([
                    'generated_document_version' => 'The selected version must belong to the same targeted resume document.',
                ]);
            }

            $changedAt = isset($selection['changed_at'])
                ? CarbonImmutable::parse($selection['changed_at'])
                : CarbonImmutable::now();

            $this->ensureChronology($application, $changedAt);

            $application->forceFill([
                'generated_document_version_id' => $version->getKey(),
                'resume_version_id' => $version->source_resume_version_id,
            ]);
            $application->setRelation('generatedDocumentVersion', $version);
            $application->setRelation('resumeVersion', $version->sourceResumeVersion);

            $readiness = $this->readinessChecker->check($application);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'generated_document_version' => array_column(
                        $readiness['blockers'],
                        'message',
                    ),
                ]);
            }

            $application->save();
            $application->documentVersionHistory()->create([
                'changed_by' => $actor->getKey(),
                'generated_document_id' => $version->generated_document_id,
                'previous_generated_document_version_id' => $previousVersion->getKey(),
                'generated_document_version_id' => $version->getKey(),
                'previous_resume_version_id' => $previousVersion->source_resume_version_id,
                'resume_version_id' => $version->source_resume_version_id,
                'previous_version_number' => $previousVersion->version_number,
                'version_number' => $version->version_number,
                'previous_checksum_sha256' => $previousVersion->checksum_sha256,
                'checksum_sha256' => $version->checksum_sha256,
                'previous_reviewed_content_sha256' => $previousVersion->reviewed_content_sha256,
                'reviewed_content_sha256' => $version->reviewed_content_sha256,
                'changed_at' => $changedAt,
                'notes' => $this->nullableTrimmed($selection['notes'] ?? null),
            ]);

            return $application->fresh($this->applicationRelations());
        });
    }

    private function validatedSelection(array $input): array
    {
        return Validator::make(['selection' => $input], [
            'selection' => ['present', 'array:changed_at,notes'],
            'selection.changed_at' => ['nullable', 'date'],
            'selection.notes' => ['nullable', 'string', 'max:2000'],
        ])->validate()['selection'];
    }

    private function ensureChronology(
        JobApplication $application,
        CarbonImmutable $changedAt,
    ): void {
        if ($changedAt->isFuture()) {
            throw ValidationException::withMessages([
                'changed_at' => 'A document selection cannot be recorded in the future.',
            ]);
        }

        $latestHistory = JobApplicationDocumentVersionHistory::query()
            ->where('job_application_id', $application->getKey())
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latestHistory !== null && $changedAt->lt($latestHistory->changed_at)) {
            throw ValidationException::withMessages([
                'changed_at' => 'The document selection cannot precede the latest selection history entry.',
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
            'trackingHistory.changedBy',
            'documentVersionHistory.changedBy',
            'generatedDocuments',
        ];
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
