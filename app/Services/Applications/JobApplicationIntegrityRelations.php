<?php

namespace App\Services\Applications;

class JobApplicationIntegrityRelations
{
    public function all(): array
    {
        return [
            'profile',
            'resumeVersion.resume',
            'generatedDocumentVersion.generatedDocument',
            'generatedDocumentVersion.sourceResumeVersion.resume',
            'statusHistory.changedBy',
            'submissionConfirmation.recordedBy',
            'scheduledEvents.statusHistory.changedBy',
            'scheduledEventReplacements.previousEvent',
            'scheduledEventReplacements.replacementEvent',
        ];
    }
}
