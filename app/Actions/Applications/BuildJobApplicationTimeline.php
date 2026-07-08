<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BuildJobApplicationTimeline
{
    public function __construct(
        private readonly JobApplicationTimelineBuilder $builder,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input = [],
    ): array {
        $application = JobApplication::query()
            ->with([
                'profile',
                'statusHistory.changedBy',
                'trackingHistory.changedBy',
                'documentVersionHistory.changedBy',
                'documentAccessHistory.accessedBy',
            ])
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        $options = Validator::make(['options' => $input], [
            'options' => ['present', 'array:event_types,direction,limit'],
            'options.event_types' => ['nullable', 'array', 'min:1'],
            'options.event_types.*' => [
                'string',
                'distinct',
                Rule::in(JobApplicationTimelineBuilder::EVENT_TYPES),
            ],
            'options.direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'options.limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ])->validate()['options'];

        return $this->builder->build(
            $application,
            array_values(
                $options['event_types'] ?? JobApplicationTimelineBuilder::EVENT_TYPES,
            ),
            (string) ($options['direction'] ?? 'desc'),
            (int) ($options['limit'] ?? 100),
        );
    }
}
