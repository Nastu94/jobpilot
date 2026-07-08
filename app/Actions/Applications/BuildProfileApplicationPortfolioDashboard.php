<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationIntegrityRelations;
use App\Services\Applications\ProfileApplicationPortfolioDashboardBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

class BuildProfileApplicationPortfolioDashboard
{
    public function __construct(
        private readonly ProfileApplicationPortfolioDashboardBuilder $builder,
        private readonly JobApplicationIntegrityRelations $relations,
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
                'array:reference_at,upcoming_days,priority_limit',
            ],
            'options.reference_at' => ['nullable', 'date'],
            'options.upcoming_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'options.priority_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate()['options'];

        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->with($this->relations->all())
            ->get();
        $referenceAt = isset($options['reference_at'])
            ? CarbonImmutable::parse($options['reference_at'])
            : CarbonImmutable::now();

        return $this->builder->build(
            $profile,
            $applications,
            $referenceAt,
            (int) ($options['upcoming_days'] ?? 7),
            (int) ($options['priority_limit'] ?? 25),
        );
    }
}
