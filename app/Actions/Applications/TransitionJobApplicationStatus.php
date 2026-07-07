<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransitionJobApplicationStatus
{
    private const TRANSITIONS = [
        'draft' => ['applied', 'withdrawn'],
        'applied' => ['screening', 'assessment', 'interview', 'offer', 'rejected', 'withdrawn'],
        'screening' => ['assessment', 'interview', 'offer', 'rejected', 'withdrawn'],
        'assessment' => ['interview', 'offer', 'rejected', 'withdrawn'],
        'interview' => ['assessment', 'offer', 'rejected', 'withdrawn'],
        'offer' => ['hired', 'rejected', 'withdrawn'],
        'hired' => [],
        'rejected' => [],
        'withdrawn' => [],
    ];

    private const TERMINAL_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplication {
        return DB::transaction(function () use ($application, $actor, $input): JobApplication {
            $application = JobApplication::query()
                ->with(['profile', 'generatedDocumentVersion'])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $transition = $this->validatedTransition($input);
            $targetStatus = $transition['status'];
            $currentStatus = $application->status;

            if ($targetStatus === $currentStatus) {
                return $application->fresh($this->applicationRelations());
            }

            $this->ensureTransitionIsAllowed($currentStatus, $targetStatus);

            if ($currentStatus === 'draft' && $targetStatus === 'applied') {
                $this->ensureApplicationIsReady($application);
            }

            $changedAt = isset($transition['changed_at'])
                ? CarbonImmutable::parse($transition['changed_at'])
                : CarbonImmutable::now();
            $latestHistory = JobApplicationStatusHistory::query()
                ->where('job_application_id', $application->getKey())
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latestHistory !== null && $changedAt->lt($latestHistory->changed_at)) {
                throw ValidationException::withMessages([
                    'changed_at' => 'The transition date cannot precede the latest status history entry.',
                ]);
            }

            if ($changedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'changed_at' => 'A status transition cannot be recorded in the future.',
                ]);
            }

            $hasNextActionInput = array_key_exists('next_action_at', $transition);
            $nextActionAt = $hasNextActionInput && $transition['next_action_at'] !== null
                ? CarbonImmutable::parse($transition['next_action_at'])
                : null;

            if (
                $nextActionAt !== null
                && ! in_array($targetStatus, self::TERMINAL_STATUSES, true)
                && $nextActionAt->lt($changedAt)
            ) {
                throw ValidationException::withMessages([
                    'next_action_at' => 'The next action cannot precede the status transition.',
                ]);
            }

            $updates = [
                'status' => $targetStatus,
            ];

            if ($targetStatus === 'applied' && $application->applied_at === null) {
                $updates['applied_at'] = $changedAt;
            }

            if (array_key_exists('application_channel', $transition)) {
                $updates['application_channel'] = $this->nullableSquished(
                    $transition['application_channel'],
                );
            }

            if (array_key_exists('external_reference', $transition)) {
                $updates['external_reference'] = $this->nullableSquished(
                    $transition['external_reference'],
                );
            }

            if (in_array($targetStatus, self::TERMINAL_STATUSES, true)) {
                $updates['next_action_at'] = null;
            } elseif ($hasNextActionInput) {
                $updates['next_action_at'] = $nextActionAt;
            }

            $application->forceFill($updates)->save();
            $application->statusHistory()->create([
                'from_status' => $currentStatus,
                'status' => $targetStatus,
                'changed_by' => $actor->getKey(),
                'changed_at' => $changedAt,
                'notes' => $this->nullableSquished($transition['notes'] ?? null),
            ]);

            return $application->fresh($this->applicationRelations());
        });
    }

    private function validatedTransition(array $input): array
    {
        $statuses = array_keys(self::TRANSITIONS);

        return Validator::make(['transition' => $input], [
            'transition' => [
                'required',
                'array:status,changed_at,notes,application_channel,external_reference,next_action_at',
            ],
            'transition.status' => ['required', 'string', Rule::in($statuses)],
            'transition.changed_at' => ['nullable', 'date'],
            'transition.notes' => ['nullable', 'string', 'max:2000'],
            'transition.application_channel' => ['nullable', 'string', 'max:50'],
            'transition.external_reference' => ['nullable', 'string', 'max:255'],
            'transition.next_action_at' => ['nullable', 'date'],
        ])->validate()['transition'];
    }

    private function ensureTransitionIsAllowed(string $currentStatus, string $targetStatus): void
    {
        if (! array_key_exists($currentStatus, self::TRANSITIONS)) {
            throw ValidationException::withMessages([
                'status' => 'The current application status is not supported by this workflow.',
            ]);
        }

        if (! in_array($targetStatus, self::TRANSITIONS[$currentStatus], true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'The transition from %s to %s is not allowed.',
                    $currentStatus,
                    $targetStatus,
                ),
            ]);
        }
    }

    private function ensureApplicationIsReady(JobApplication $application): void
    {
        $readiness = $this->readinessChecker->check($application);

        if ($readiness['ready']) {
            return;
        }

        throw ValidationException::withMessages([
            'submission_readiness' => array_column($readiness['blockers'], 'message'),
        ]);
    }

    private function applicationRelations(): array
    {
        return [
            'jobPosting',
            'resumeVersion',
            'generatedDocumentVersion',
            'statusHistory.changedBy',
            'generatedDocuments',
        ];
    }

    private function nullableSquished(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::squish($value);

        return $value === '' ? null : $value;
    }
}
