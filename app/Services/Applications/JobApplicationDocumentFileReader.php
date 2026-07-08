<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class JobApplicationDocumentFileReader
{
    public const SOURCE_SELECTED_VERSION = 'selected_version';

    public const SOURCE_SUBMITTED_SNAPSHOT = 'submitted_snapshot';

    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
    ) {
    }

    public function read(JobApplication $application): array
    {
        $application->loadMissing([
            'profile',
            'resumeVersion.resume',
            'generatedDocumentVersion.generatedDocument',
            'generatedDocumentVersion.sourceResumeVersion.resume',
        ]);

        $descriptor = $application->status === 'draft'
            ? $this->selectedVersionDescriptor($application)
            : $this->submittedSnapshotDescriptor($application);

        return $this->verifiedFile($application, $descriptor);
    }

    private function selectedVersionDescriptor(JobApplication $application): array
    {
        $readiness = $this->readinessChecker->check($application);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'document_file' => array_column($readiness['blockers'], 'message'),
            ]);
        }

        $version = $application->generatedDocumentVersion;

        return [
            'document_source' => self::SOURCE_SELECTED_VERSION,
            'generated_document_version_id' => $version->getKey(),
            'source_resume_version_id' => $version->source_resume_version_id,
            'filename' => $version->filename,
            'mime_type' => $version->mime_type,
            'file_size' => $version->file_size,
            'checksum_sha256' => $version->checksum_sha256,
            'content_sha256' => $version->reviewed_content_sha256,
            'storage_disk' => $version->storage_disk,
            'storage_path' => $version->storage_path,
        ];
    }

    private function submittedSnapshotDescriptor(JobApplication $application): array
    {
        $required = [
            'submitted_generated_document_version_id',
            'submitted_source_resume_version_id',
            'submitted_document_filename',
            'submitted_document_mime_type',
            'submitted_document_file_size',
            'submitted_document_checksum_sha256',
            'submitted_document_content_sha256',
            'submitted_document_storage_disk',
            'submitted_document_storage_path',
        ];

        foreach ($required as $field) {
            $value = $application->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                throw ValidationException::withMessages([
                    'document_file' => 'The application has no complete submitted document snapshot.',
                ]);
            }
        }

        return [
            'document_source' => self::SOURCE_SUBMITTED_SNAPSHOT,
            'generated_document_version_id' => $application->submitted_generated_document_version_id,
            'source_resume_version_id' => $application->submitted_source_resume_version_id,
            'filename' => $application->submitted_document_filename,
            'mime_type' => $application->submitted_document_mime_type,
            'file_size' => $application->submitted_document_file_size,
            'checksum_sha256' => $application->submitted_document_checksum_sha256,
            'content_sha256' => $application->submitted_document_content_sha256,
            'storage_disk' => $application->submitted_document_storage_disk,
            'storage_path' => $application->submitted_document_storage_path,
        ];
    }

    private function verifiedFile(JobApplication $application, array $descriptor): array
    {
        if ($descriptor['storage_disk'] !== 'local') {
            $this->fail('The application document is not stored on the expected private disk.');
        }

        $filename = trim((string) $descriptor['filename']);
        $mimeType = trim((string) $descriptor['mime_type']);
        $path = trim((string) $descriptor['storage_path']);
        $checksum = strtolower(trim((string) $descriptor['checksum_sha256']));
        $contentChecksum = strtolower(trim((string) $descriptor['content_sha256']));
        $fileSize = (int) $descriptor['file_size'];
        $versionId = (int) $descriptor['generated_document_version_id'];

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            $this->fail('The application document filename is not safe.');
        }

        if ($mimeType === '' || str_contains($mimeType, "\n") || str_contains($mimeType, "\r")) {
            $this->fail('The application document MIME type is not valid.');
        }

        if ($fileSize < 1) {
            $this->fail('The application document size metadata is not valid.');
        }

        if (! $this->isSha256($checksum) || ! $this->isSha256($contentChecksum)) {
            $this->fail('The application document checksum metadata is not valid.');
        }

        if (! hash_equals($checksum, $contentChecksum)) {
            $this->fail('The exported file checksum does not match the approved content checksum.');
        }

        $this->ensureExpectedPath(
            $application,
            $versionId,
            $filename,
            $path,
        );

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($path)) {
                $this->fail('The private application document file is missing.');
            }

            $contents = $disk->get($path);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('The private application document file could not be read.');
        }

        $actualChecksum = hash('sha256', $contents);
        $actualSize = strlen($contents);

        if ($actualSize !== $fileSize) {
            $this->fail('The private application document size no longer matches its recorded metadata.');
        }

        if (! hash_equals($checksum, $actualChecksum)) {
            $this->fail('The private application document no longer matches its recorded checksum.');
        }

        return [
            'application_id' => $application->getKey(),
            'document_source' => $descriptor['document_source'],
            'generated_document_version_id' => $versionId,
            'source_resume_version_id' => (int) $descriptor['source_resume_version_id'],
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $actualSize,
            'checksum_sha256' => $actualChecksum,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'contents' => $contents,
        ];
    }

    private function ensureExpectedPath(
        JobApplication $application,
        int $versionId,
        string $filename,
        string $path,
    ): void {
        if (
            $versionId < 1
            || $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)
        ) {
            $this->fail('The private application document path is not valid.');
        }

        $pattern = sprintf(
            '#\Agenerated-documents/profile-%d/document-[1-9][0-9]*/version-%d/([^/]+)\z#',
            $application->profile_id,
            $versionId,
        );

        if (! preg_match($pattern, $path, $matches) || $matches[1] !== $filename) {
            $this->fail('The private application document path is outside the expected location.');
        }
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'document_file' => $message,
        ]);
    }
}
