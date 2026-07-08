<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\ProfileApplicationLifecycleAnalyticsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BuildProfileApplicationLifecycleAnalytics
{
    public function __construct(
        private readonly ProfileApplicationLifecycleAnalyticsBuilder $builder,
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
            'options' => ['present', 'array:reference_at'],
            'options.reference_at' => ['nullable', 'date'],
        ])->validate()['options'];
        $referenceAt = isset($options['reference_at'])
            ? CarbonImmutable::parse($options['reference_at'])
            : CarbonImmutable::now();

        if ($referenceAt->isFuture()) {
            throw ValidationException::withMessages([
                'reference_at' => 'The analytics reference date cannot be in the future.',
            ]);
        }

        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->with('statusHistory')
            ->get();

        return $this->builder->build(
            $profile,
            $applications,
            $referenceAt,
        );
    }
}
