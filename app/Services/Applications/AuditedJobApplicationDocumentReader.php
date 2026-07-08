<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\User;
use Carbon\CarbonImmutable;

class AuditedJobApplicationDocumentReader
{
    public function __construct(
        private readonly JobApplicationDocumentFileReader $fileReader,
    ) {
    }

    public function read(JobApplication $application, User $actor): array
    {
        $file = $this->fileReader->read($application);
        $accessedAt = CarbonImmutable::now();
        $history = $application->documentAccessHistory()->create([
            'accessed_by' => $actor->getKey(),
            'document_source' => $file['document_source'],
            'generated_document_version_id' => $file['generated_document_version_id'],
            'source_resume_version_id' => $file['source_resume_version_id'],
            'filename' => $file['filename'],
            'mime_type' => $file['mime_type'],
            'file_size' => $file['file_size'],
            'checksum_sha256' => $file['checksum_sha256'],
            'storage_disk' => $file['storage_disk'],
            'storage_path' => $file['storage_path'],
            'accessed_at' => $accessedAt,
        ]);

        return array_merge($file, [
            'access_history_id' => $history->getKey(),
            'accessed_at' => $accessedAt->toISOString(),
        ]);
    }
}
