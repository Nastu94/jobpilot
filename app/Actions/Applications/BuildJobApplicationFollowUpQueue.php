<?php

namespace App\Actions\Applications;

use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationFollowUpQueueBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

class BuildJobApplicationFollowUpQueue
{
    public function __construct(
        private readonly JobApplicationFollowUpQueueBuilder $builder,
    ) {
    }

    public function execute(
        Profile $profile,
        User $actor,
        array $input = [],
    ): array {
        $profile = Profile::query()->findOrFail($profile->getKey());

        if ((int) $profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this candidate profile.');
        }

        $options = Validator::make(['options' => $input], [
            'options' => [
                'present',
                'array:reference_at,upcoming_days,limit_per_bucket',
            ],
            'options.reference_at' => ['nullable', 'date'],
            'options.upcoming_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'options.limit_per_bucket' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate()['options'];

        $referenceAt = isset($options['reference_at'])
            ? CarbonImmutable::parse($options['reference_at'])
            : CarbonImmutable::now();

        return $this->builder->build(
            $profile,
            $referenceAt,
            (int) ($options['upcoming_days'] ?? 7),
            (int) ($options['limit_per_bucket'] ?? 25),
        );
    }
}
