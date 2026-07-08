<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\JobApplicationWorkspaceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

class BuildJobApplicationWorkspace
{
    public function __construct(
        private readonly JobApplicationWorkspaceBuilder $builder,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input = [],
    ): array {
        $options = Validator::make(['options' => $input], [
            'options' => [
                'present',
                'array:reference_at,upcoming_days,timeline_limit',
            ],
            'options.reference_at' => ['nullable', 'date'],
            'options.upcoming_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'options.timeline_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate()['options'];

        $application = JobApplication::query()
            ->with([
                'profile',
                'jobPosting',
                'resumeVersion.resume',
                'generatedDocumentVersion.generatedDocument',
                'generatedDocumentVersion.sourceResumeVersion.resume',
                'statusHistory.changedBy',
                'submissionConfirmation.recordedBy',
                'trackingHistory.changedBy',
                'interactions.recordedBy',
                'scheduledEvents.statusHistory.changedBy',
                'scheduledEvents.replacementRecord',
                'scheduledEvents.replacesRecord',
                'scheduledEventReplacements.changedBy',
                'documentVersionHistory.changedBy',
                'documentAccessHistory.accessedBy',
            ])
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        $referenceAt = isset($options['reference_at'])
            ? CarbonImmutable::parse($options['reference_at'])
            : CarbonImmutable::now();

        return $this->builder->build(
            $application,
            $referenceAt,
            (int) ($options['upcoming_days'] ?? 7),
            (int) ($options['timeline_limit'] ?? 10),
        );
    }
}
