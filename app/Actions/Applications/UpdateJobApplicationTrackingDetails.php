<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationTrackingHistory;
use App\Models\User;
use App\Services\Applications\JobApplicationTrackingRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateJobApplicationTrackingDetails
{
    private const TERMINAL_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    private const TRACKED_FIELDS = [
        'application_channel',
        'external_reference',
        'next_action_at',
        'notes',
    ];

    public function __construct(
        private readonly JobApplicationTrackingRecorder $trackingRecorder,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplication {
        return DB::transaction(function () use ($application, $actor, $input): JobApplication {
            $application = JobApplication::query()
                ->with('profile')
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $tracking = $this->validatedTracking($input);
            $changedAt = isset($tracking['changed_at'])
                ? CarbonImmutable::parse($tracking['changed_at'])
                : CarbonImmutable::now();

            $this->ensureChronology($application, $changedAt);
            $updates = $this->updates($application, $tracking, $changedAt);
            $before = $this->trackingRecorder->snapshot($application);

            $application->forceFill($updates);

            if (! $application->isDirty(array_keys($updates))) {
                return $application->fresh($this->applicationRelations());
            }

            $application->save();
            $this->trackingRecorder->record(
                $application,
                $actor,
                $changedAt,
                JobApplicationTrackingRecorder::SOURCE_MANUAL_UPDATE,
                $before,
            );

            return $application->fresh($this->applicationRelations());
        });
    }

    private function validatedTracking(array $input): array
    {
        $validator = Validator::make(['tracking' => $input], [
            'tracking' => [
                'required',
                'array:changed_at,application_channel,external_reference,next_action_at,notes',
            ],
            'tracking.changed_at' => ['nullable', 'date'],
            'tracking.application_channel' => ['nullable', 'string', 'max:50'],
            'tracking.external_reference' => ['nullable', 'string', 'max:255'],
            'tracking.next_action_at' => ['nullable', 'date'],
            'tracking.notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $validator->after(function ($validator) use ($input): void {
            foreach (self::TRACKED_FIELDS as $field) {
                if (array_key_exists($field, $input)) {
                    return;
                }
            }

            $validator->errors()->add(
                'tracking',
                'At least one tracking field must be provided.',
            );
        });

        return $validator->validate()['tracking'];
    }

    private function ensureChronology(
        JobApplication $application,
        CarbonImmutable $changedAt,
    ): void {
        if ($changedAt->isFuture()) {
            throw ValidationException::withMessages([
                'changed_at' => 'A tracking update cannot be recorded in the future.',
            ]);
        }

        $latestHistory = JobApplicationTrackingHistory::query()
            ->where('job_application_id', $application->getKey())
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latestHistory !== null && $changedAt->lt($latestHistory->changed_at)) {
            throw ValidationException::withMessages([
                'changed_at' => 'The tracking update cannot precede the latest tracking history entry.',
            ]);
        }
    }

    private function updates(
        JobApplication $application,
        array $tracking,
        CarbonImmutable $changedAt,
    ): array {
        $updates = [];

        if (array_key_exists('application_channel', $tracking)) {
            $updates['application_channel'] = $this->nullableSquished(
                $tracking['application_channel'],
            );
        }

        if (array_key_exists('external_reference', $tracking)) {
            $updates['external_reference'] = $this->nullableSquished(
                $tracking['external_reference'],
            );
        }

        if (array_key_exists('notes', $tracking)) {
            $updates['notes'] = $this->nullableTrimmed($tracking['notes']);
        }

        if (array_key_exists('next_action_at', $tracking)) {
            $nextActionAt = $tracking['next_action_at'] === null
                ? null
                : CarbonImmutable::parse($tracking['next_action_at']);

            if (
                $nextActionAt !== null
                && in_array($application->status, self::TERMINAL_STATUSES, true)
            ) {
                throw ValidationException::withMessages([
                    'next_action_at' => 'A terminal application cannot have a next action.',
                ]);
            }

            if ($nextActionAt !== null && $nextActionAt->lte(CarbonImmutable::now())) {
                throw ValidationException::withMessages([
                    'next_action_at' => 'The next action must be scheduled in the future.',
                ]);
            }

            if ($nextActionAt !== null && $nextActionAt->lt($changedAt)) {
                throw ValidationException::withMessages([
                    'next_action_at' => 'The next action cannot precede the tracking update.',
                ]);
            }

            $updates['next_action_at'] = $nextActionAt;
        }

        return $updates;
    }

    private function applicationRelations(): array
    {
        return [
            'jobPosting',
            'resumeVersion',
            'generatedDocumentVersion',
            'statusHistory.changedBy',
            'trackingHistory.changedBy',
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

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
