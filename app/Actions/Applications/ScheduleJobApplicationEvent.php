<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use App\Models\User;
use App\Services\Applications\JobApplicationScheduledEventPayloadBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleJobApplicationEvent
{
    private const TERMINAL_APPLICATION_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function __construct(
        private readonly JobApplicationScheduledEventPayloadBuilder $payloadBuilder,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplicationScheduledEvent {
        return DB::transaction(function () use ($application, $actor, $input): JobApplicationScheduledEvent {
            $application = JobApplication::query()
                ->with('profile')
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            if (in_array($application->status, self::TERMINAL_APPLICATION_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'job_application' => 'A terminal application cannot receive a new scheduled event.',
                ]);
            }

            $attributes = $this->payloadBuilder->build($input, $actor);

            if ($attributes['client_reference'] !== null) {
                $existing = JobApplicationScheduledEvent::query()
                    ->where('job_application_id', $application->getKey())
                    ->where('client_reference', $attributes['client_reference'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! $this->payloadBuilder->same($existing, $attributes)) {
                        throw ValidationException::withMessages([
                            'client_reference' => 'The client reference is already associated with another scheduled event payload.',
                        ]);
                    }

                    return $existing->load([
                        'createdBy',
                        'resolvedBy',
                        'statusHistory.changedBy',
                    ]);
                }
            }

            $scheduledEvent = $application->scheduledEvents()->create($attributes);
            $scheduledEvent->statusHistory()->create([
                'job_application_id' => $application->getKey(),
                'changed_by' => $actor->getKey(),
                'from_status' => null,
                'status' => 'planned',
                'changed_at' => CarbonImmutable::now(),
                'notes' => null,
            ]);

            return $scheduledEvent->load([
                'createdBy',
                'resolvedBy',
                'statusHistory.changedBy',
            ]);
        });
    }
}
