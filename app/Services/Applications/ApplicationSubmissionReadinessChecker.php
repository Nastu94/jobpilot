<?php

namespace App\Services\Applications;

use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ApplicationSubmissionReadinessChecker
{
    public function check(JobApplication $application): array
    {
        $application->loadMissing([
            'profile',
            'resumeVersion.resume',
            'generatedDocumentVersion.generatedDocument',
            'generatedDocumentVersion.sourceResumeVersion.resume',
        ]);

        $blockers = [];

        $this->addWhen(
            $blockers,
            $application->status !== 'draft',
            'application_not_draft',
            'Only a draft application can be prepared for submission.',
        );

        $version = $application->generatedDocumentVersion;

        if ($version === null) {
            $this->add($blockers, 'selected_version_missing', 'No generated document version is selected.');

            return $this->result($application, $blockers);
        }

        $document = $version->generatedDocument;

        if ($document === null) {
            $this->add($blockers, 'generated_document_missing', 'The selected version has no generated document.');

            return $this->result($application, $blockers);
        }

        $this->addWhen($blockers, (int) $document->profile_id !== (int) $application->profile_id, 'document_profile_mismatch', 'The selected document belongs to another profile.');
        $this->addWhen($blockers, $document->document_type !== 'targeted_resume', 'document_type_not_supported', 'The selected document is not a targeted resume.');
        $this->addWhen($blockers, $document->status !== 'ready', 'document_not_ready', 'The selected document is not ready.');
        $this->addWhen($blockers, (int) $document->job_application_id !== (int) $application->getKey(), 'document_application_mismatch', 'The selected document is not linked to this application.');
        $this->addWhen(
            $blockers,
            $application->job_posting_id !== null
                && $document->job_posting_id !== null
                && (int) $application->job_posting_id !== (int) $document->job_posting_id,
            'document_posting_mismatch',
            'The selected document targets another job posting.',
        );

        $this->checkVersion($application, $version, $blockers);

        return $this->result($application, $blockers);
    }

    private function checkVersion(JobApplication $application, GeneratedDocumentVersion $version, array &$blockers): void
    {
        $this->addWhen($blockers, $version->review_status !== 'approved', 'version_not_approved', 'The selected version is not approved.');
        $this->addWhen($blockers, $version->contains_unverified_claims, 'version_has_unverified_claims', 'The selected version contains unverified claims.');
        $this->addWhen(
            $blockers,
            $version->generator_key === DeterministicTargetedResumeDraftBuilder::KEY,
            'technical_review_draft_selected',
            'A technical matching review draft cannot be submitted.',
        );
        $this->addWhen($blockers, $version->source_resume_version_id === null, 'source_resume_missing', 'The selected version has no source resume.');
        $this->addWhen(
            $blockers,
            $version->source_resume_version_id !== null
                && (int) $application->resume_version_id !== (int) $version->source_resume_version_id,
            'source_resume_selection_mismatch',
            'The application and selected version reference different source resumes.',
        );

        $sourceResume = $version->sourceResumeVersion?->resume;
        $this->addWhen(
            $blockers,
            $sourceResume !== null && (int) $sourceResume->profile_id !== (int) $application->profile_id,
            'source_resume_profile_mismatch',
            'The selected source resume belongs to another profile.',
        );

        $content = (string) $version->content;
        $contentHash = hash('sha256', $content);

        $this->addWhen($blockers, trim($content) === '', 'version_content_missing', 'The selected version has no final text content.');
        $this->addWhen(
            $blockers,
            trim((string) $version->reviewed_content_sha256) === ''
                || ! hash_equals((string) $version->reviewed_content_sha256, $contentHash),
            'reviewed_content_mismatch',
            'The current content does not match the approved content.',
        );

        $this->checkExport($application, $version, $content, $contentHash, $blockers);
    }

    private function checkExport(JobApplication $application, GeneratedDocumentVersion $version, string $content, string $contentHash, array &$blockers): void
    {
        $this->addWhen($blockers, $version->storage_disk !== 'local', 'export_disk_not_private', 'The export is not stored on the expected private disk.');

        $path = trim((string) $version->storage_path);

        if ($path === '') {
            $this->add($blockers, 'export_path_missing', 'The selected version has not been exported.');

            return;
        }

        $prefix = sprintf('generated-documents/profile-%d/document-%d/version-%d/', $application->profile_id, $version->generated_document_id, $version->getKey());
        $this->addWhen($blockers, ! str_starts_with($path, $prefix), 'export_path_unexpected', 'The export path is not the expected private location.');
        $this->addWhen($blockers, ! hash_equals((string) $version->checksum_sha256, $contentHash), 'export_checksum_metadata_mismatch', 'The export checksum does not match the approved content.');
        $this->addWhen($blockers, (int) $version->file_size !== strlen($content), 'export_size_metadata_mismatch', 'The export size does not match the approved content.');
        $this->addWhen($blockers, trim((string) $version->filename) === '' || trim((string) $version->mime_type) === '', 'export_metadata_incomplete', 'The export metadata is incomplete.');

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($path)) {
                $this->add($blockers, 'export_file_missing', 'The exported file is missing from private storage.');

                return;
            }

            $this->addWhen(
                $blockers,
                ! hash_equals($contentHash, hash('sha256', $disk->get($path))),
                'export_file_checksum_mismatch',
                'The exported file no longer matches the approved content.',
            );
        } catch (Throwable) {
            $this->add($blockers, 'export_file_unreadable', 'The exported file could not be read.');
        }
    }

    private function result(JobApplication $application, array $blockers): array
    {
        return [
            'application_id' => $application->getKey(),
            'ready' => $blockers === [],
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'blockers' => $blockers,
        ];
    }

    private function addWhen(array &$blockers, bool $condition, string $code, string $message): void
    {
        if ($condition) {
            $this->add($blockers, $code, $message);
        }
    }

    private function add(array &$blockers, string $code, string $message): void
    {
        $blockers[] = ['code' => $code, 'message' => $message];
    }
}
