<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationTrackingHistory;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

class JobApplicationTrackingRecorder
{
    public const SOURCE_MANUAL_UPDATE = 'manual_update';

    public const SOURCE_STATUS_TRANSITION = 'status_transition';

    public function snapshot(JobApplication $application): array
    {
        return [
            'application_channel' => $application->application_channel,
            'external_reference' => $application->external_reference,
            'next_action_at' => $application->next_action_at?->toImmutable(),
            'notes' => $application->notes,
        ];
    }

    public function record(
        JobApplication $application,
        User $actor,
        DateTimeInterface $changedAt,
        string $source,
        array $before,
    ): ?JobApplicationTrackingHistory {
        $application->refresh();
        $after = $this->snapshot($application);

        if ($this->comparable($before) === $this->comparable($after)) {
            return null;
        }

        $latestHistory = JobApplicationTrackingHistory::query()
            ->where('job_application_id', $application->getKey())
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latestHistory !== null && $changedAt < $latestHistory->changed_at) {
            throw ValidationException::withMessages([
                'changed_at' => 'The tracking change cannot precede the latest tracking history entry.',
            ]);
        }

        return $application->trackingHistory()->create([
            'changed_by' => $actor->getKey(),
            'change_source' => $source,
            'previous_application_channel' => $before['application_channel'],
            'application_channel' => $after['application_channel'],
            'previous_external_reference' => $before['external_reference'],
            'external_reference' => $after['external_reference'],
            'previous_next_action_at' => $before['next_action_at'],
            'next_action_at' => $after['next_action_at'],
            'previous_notes' => $before['notes'],
            'notes' => $after['notes'],
            'changed_at' => $changedAt,
        ]);
    }

    private function comparable(array $snapshot): array
    {
        return [
            'application_channel' => $snapshot['application_channel'],
            'external_reference' => $snapshot['external_reference'],
            'next_action_at' => $snapshot['next_action_at']?->format('Y-m-d H:i:s'),
            'notes' => $snapshot['notes'],
        ];
    }
}
