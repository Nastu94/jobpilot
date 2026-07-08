<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\ProfileApplicationCohortAnalyticsBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuildProfileApplicationCohortAnalytics
{
    private const MAX_PERIODS = 60;

    public function __construct(
        private readonly ProfileApplicationCohortAnalyticsBuilder $builder,
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
                'array:reference_at,start_at,end_at,granularity',
            ],
            'options.reference_at' => ['nullable', 'date'],
            'options.start_at' => ['nullable', 'date'],
            'options.end_at' => ['nullable', 'date'],
            'options.granularity' => [
                'nullable',
                'string',
                Rule::in(ProfileApplicationCohortAnalyticsBuilder::GRANULARITIES),
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

        $granularity = $options['granularity'] ?? 'month';
        $endAt = isset($options['end_at'])
            ? CarbonImmutable::parse($options['end_at'])
            : $referenceAt;
        $startAt = isset($options['start_at'])
            ? CarbonImmutable::parse($options['start_at'])
            : ($granularity === 'week'
                ? $referenceAt
                    ->startOfWeek(CarbonInterface::MONDAY)
                    ->subWeeks(11)
                : $referenceAt->startOfMonth()->subMonths(11));

        if ($startAt->gt($endAt)) {
            throw ValidationException::withMessages([
                'start_at' => 'The cohort start date must not follow the end date.',
            ]);
        }

        if ($endAt->gt($referenceAt)) {
            throw ValidationException::withMessages([
                'end_at' => 'The cohort end date cannot follow the analytics reference date.',
            ]);
        }

        if ($this->periodCount($startAt, $endAt, $granularity) > self::MAX_PERIODS) {
            throw ValidationException::withMessages([
                'start_at' => sprintf(
                    'The cohort range cannot exceed %d periods.',
                    self::MAX_PERIODS,
                ),
            ]);
        }

        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', [$startAt, $endAt])
            ->with('statusHistory')
            ->get();

        return $this->builder->build(
            $profile,
            $applications,
            $referenceAt,
            $startAt,
            $endAt,
            $granularity,
        );
    }

    private function periodCount(
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        string $granularity,
    ): int {
        $cursor = $granularity === 'week'
            ? $startAt->startOfWeek(CarbonInterface::MONDAY)
            : $startAt->startOfMonth();
        $count = 0;

        while ($cursor->lte($endAt)) {
            $count++;
            $cursor = $granularity === 'week'
                ? $cursor->addWeek()
                : $cursor->addMonth();
        }

        return $count;
    }
}
