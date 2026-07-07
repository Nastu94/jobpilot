<?php

namespace App\Actions\Documents;

use App\Models\GeneratedDocumentVersion;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExportApprovedGeneratedDocumentVersion
{
    private const DISK = 'local';

    private const FORMATS = [
        'markdown' => [
            'extension' => 'md',
            'mime_type' => 'text/markdown',
        ],
        'plain_text' => [
            'extension' => 'txt',
            'mime_type' => 'text/plain',
        ],
    ];

    public function execute(
        GeneratedDocumentVersion $version,
        User $actor,
    ): GeneratedDocumentVersion {
        return DB::transaction(function () use ($version, $actor): GeneratedDocumentVersion {
            $version = GeneratedDocumentVersion::query()
                ->with('generatedDocument.profile')
                ->lockForUpdate()
                ->findOrFail($version->getKey());
            $document = $version->generatedDocument;

            if ((int) $document->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this generated document.');
            }

            $this->ensureVersionCanBeExported($version);

            $content = (string) $version->content;
            $checksum = hash('sha256', $content);
            $format = self::FORMATS[$version->content_format];
            $filename = $this->filename($version, $format['extension']);
            $path = sprintf(
                'generated-documents/profile-%d/document-%d/version-%d/%s',
                $document->profile_id,
                $document->getKey(),
                $version->getKey(),
                $filename,
            );
            $disk = Storage::disk(self::DISK);
            $storedContentMatches = $disk->exists($path)
                && hash_equals($checksum, hash('sha256', $disk->get($path)));

            if (! $storedContentMatches && ! $disk->put($path, $content)) {
                throw new RuntimeException('The approved document could not be written to storage.');
            }

            $version->forceFill([
                'storage_disk' => self::DISK,
                'storage_path' => $path,
                'filename' => $filename,
                'mime_type' => $format['mime_type'],
                'file_size' => strlen($content),
                'checksum_sha256' => $checksum,
            ])->save();

            return $version->fresh([
                'generatedDocument',
                'sourceResumeVersion',
                'matchAnalysis',
                'reviewedBy',
            ]);
        });
    }

    private function ensureVersionCanBeExported(
        GeneratedDocumentVersion $version,
    ): void {
        if ($version->review_status !== 'approved') {
            throw ValidationException::withMessages([
                'generated_document_version' => 'Only approved document versions can be exported.',
            ]);
        }

        if ($version->contains_unverified_claims) {
            throw ValidationException::withMessages([
                'generated_document_version' => 'A version containing unverified claims cannot be exported.',
            ]);
        }

        if ($version->generator_key === DeterministicTargetedResumeDraftBuilder::KEY) {
            throw ValidationException::withMessages([
                'generated_document_version' => 'A technical matching review draft must be finalized before export.',
            ]);
        }

        if (! array_key_exists((string) $version->content_format, self::FORMATS)) {
            throw ValidationException::withMessages([
                'content_format' => 'The document content format is not supported for export.',
            ]);
        }

        if ($version->content === null || trim($version->content) === '') {
            throw ValidationException::withMessages([
                'content' => 'The approved document has no text content to export.',
            ]);
        }

        if ($version->reviewed_content_sha256 === null) {
            throw ValidationException::withMessages([
                'content' => 'The approved document has no recorded review integrity hash.',
            ]);
        }

        $currentHash = hash('sha256', $version->content);

        if (! hash_equals($version->reviewed_content_sha256, $currentHash)) {
            throw ValidationException::withMessages([
                'content' => 'The document content changed after approval and must be reviewed again.',
            ]);
        }
    }

    private function filename(
        GeneratedDocumentVersion $version,
        string $extension,
    ): string {
        $baseName = Str::slug($version->generatedDocument->name);

        if ($baseName === '') {
            $baseName = 'generated-document';
        }

        return sprintf(
            '%s-v%d.%s',
            $baseName,
            $version->version_number,
            $extension,
        );
    }
}
