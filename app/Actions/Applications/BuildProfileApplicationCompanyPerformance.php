<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\ProfileApplicationCompanyPerformanceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BuildProfileApplicationCompanyPerformance
{
    private const MAX_MONTHS = 60;

    public function __construct(
        private readonly ProfileApplicationCompanyPerformanceBuilder $builder,
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
                'array:reference_at,start_at,end_at,minimum_sample_size',
            ],
            'options.reference_at' => ['nullable', 'date'],
            'options.start_at' => ['nullable', 'date'],
            'options.end_at' => ['nullable', 'date'],
            'options.minimum_sample_size' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ])->validate()['options'];
        $referenceAt = isset($options['reference_at'])
            ? CarbonImmutable::parse($options['reference_at'])
            : CarbonImmutable::now();

        if ($referenceAt->isFuture()) {
            throw ValidationException::withMessages([
                'reference_at' => 'The analytics reference date cannot be in the future.',
            ]);
        }

        $endAt = isset($options['end_at'])
            ? CarbonImmutable::parse($options['end_at'])
            : $referenceAt;
        $startAt = isset($options['start_at'])
            ? CarbonImmutable::parse($options['start_at'])
            : $referenceAt->startOfMonth()->subMonths(11);

        if ($startAt->gt($endAt)) {
            throw ValidationException::withMessages([
                'start_at' => 'The analytics start date must not follow the end date.',
            ]);
        }

        if ($endAt->gt($referenceAt)) {
            throw ValidationException::withMessages([
                'end_at' => 'The analytics end date cannot follow the reference date.',
            ]);
        }

        if ($this->monthCount($startAt, $endAt) > self::MAX_MONTHS) {
            throw ValidationException::withMessages([
                'start_at' => sprintf(
                    'The analytics range cannot exceed %d months.',
                    self::MAX_MONTHS,
                ),
            ]);
        }

        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', [$startAt, $endAt])
            ->with('statusHistory')
            ->orderBy('id')
            ->get();

        return $this->builder->build(
            $profile,
            $applications,
            $referenceAt,
            $startAt,
            $endAt,
            (int) ($options['minimum_sample_size'] ?? 3),
        );
    }

    private function monthCount(
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): int {
        $cursor = $startAt->startOfMonth();
        $count = 0;

        while ($cursor->lte($endAt)) {
            $count++;
            $cursor = $cursor->addMonth();
        }

        return $count;
    }
}
