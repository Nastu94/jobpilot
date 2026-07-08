<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationIntegrityRelations;
use App\Services\Applications\JobApplicationStatusWorkflow;
use App\Services\Applications\ProfileApplicationHealthReportBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BuildProfileApplicationHealthReport
{
    public function __construct(
        private readonly ProfileApplicationHealthReportBuilder $builder,
        private readonly JobApplicationIntegrityRelations $relations,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function execute(
        Profile $profile,
        User $actor,
        array $input = [],
    ): array {
        $profile = Profile::query()->findOrFail($profile->getKey());

        if ((int) $profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this profile.');
        }

        $options = Validator::make(['options' => $input], [
            'options' => [
                'present',
                'array:integrity_statuses,application_statuses,limit',
            ],
            'options.integrity_statuses' => ['nullable', 'array', 'min:1'],
            'options.integrity_statuses.*' => [
                'string',
                'distinct',
                Rule::in(ProfileApplicationHealthReportBuilder::INTEGRITY_STATUSES),
            ],
            'options.application_statuses' => ['nullable', 'array', 'min:1'],
            'options.application_statuses.*' => [
                'string',
                'distinct',
                Rule::in($this->statusWorkflow->statuses()),
            ],
            'options.limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ])->validate()['options'];

        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->with($this->relations->all())
            ->get();

        return $this->builder->build(
            $profile,
            $applications,
            array_values(
                $options['integrity_statuses']
                    ?? ProfileApplicationHealthReportBuilder::INTEGRITY_STATUSES,
            ),
            array_values(
                $options['application_statuses']
                    ?? $this->defaultApplicationStatuses($applications),
            ),
            (int) ($options['limit'] ?? 100),
        );
    }

    private function defaultApplicationStatuses(Collection $applications): array
    {
        $known = $this->statusWorkflow->statuses();
        $unknown = $applications
            ->pluck('status')
            ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
            ->unique()
            ->reject(fn (string $status): bool => in_array($status, $known, true))
            ->sort()
            ->values()
            ->all();

        return array_merge($known, $unknown);
    }
}
