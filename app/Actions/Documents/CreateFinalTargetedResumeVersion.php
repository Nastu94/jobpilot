<?php

namespace App\Actions\Documents;

use App\Models\GeneratedDocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreateFinalTargetedResumeVersion
{
    public const KEY = 'manual_targeted_resume_finalization';

    public const VERSION = '1.0.0';

    /**
     * @throws JsonException
     */
    public function execute(
        GeneratedDocumentVersion $baseVersion,
        User $actor,
        array $input,
    ): GeneratedDocumentVersion {
        return DB::transaction(function () use ($baseVersion, $actor, $input): GeneratedDocumentVersion {
            $baseVersion = GeneratedDocumentVersion::query()
                ->with([
                    'generatedDocument.profile',
                    'sourceResumeVersion.resume',
                    'matchAnalysis',
                ])
                ->lockForUpdate()
                ->findOrFail($baseVersion->getKey());
            $document = $baseVersion->generatedDocument;

            if ((int) $document->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this generated document.');
            }

            if ($document->document_type !== 'targeted_resume') {
                throw ValidationException::withMessages([
                    'generated_document' => 'Only targeted resume documents can create a final resume version.',
                ]);
            }

            if ($baseVersion->source_resume_version_id === null) {
                throw ValidationException::withMessages([
                    'source_resume_version' => 'The base version has no source resume.',
                ]);
            }

            if (
                (int) $baseVersion->sourceResumeVersion->resume->profile_id
                !== (int) $document->profile_id
            ) {
                throw ValidationException::withMessages([
                    'source_resume_version' => 'The source resume and generated document do not belong to the same profile.',
                ]);
            }

            $final = $this->validatedFinalVersion($input);
            $inputHash = $this->inputHash($baseVersion, $final);
            $existingVersion = GeneratedDocumentVersion::query()
                ->where('generated_document_id', $document->getKey())
                ->where('generator_key', self::KEY)
                ->where('generator_version', self::VERSION)
                ->where('input_hash', $inputHash)
                ->first();

            if ($existingVersion !== null) {
                return $existingVersion->fresh([
                    'generatedDocument',
                    'sourceResumeVersion',
                    'matchAnalysis',
                ]);
            }

            $nextVersionNumber = ((int) GeneratedDocumentVersion::query()
                ->where('generated_document_id', $document->getKey())
                ->max('version_number')) + 1;
            $document->forceFill(['status' => 'draft'])->save();

            $version = $document->versions()->create([
                'source_resume_version_id' => $baseVersion->source_resume_version_id,
                'match_analysis_id' => $baseVersion->match_analysis_id,
                'version_number' => $nextVersionNumber,
                'generation_method' => 'manual',
                'generator_key' => self::KEY,
                'generator_version' => self::VERSION,
                'input_hash' => $inputHash,
                'content_format' => $final['content_format'],
                'content' => $final['content'],
                'review_status' => 'pending',
                'contains_unverified_claims' => false,
                'change_summary' => $final['change_summary'],
            ]);

            return $version->fresh([
                'generatedDocument',
                'sourceResumeVersion',
                'matchAnalysis',
            ]);
        });
    }

    private function validatedFinalVersion(array $input): array
    {
        return Validator::make(['version' => $input], [
            'version' => ['required', 'array:content,content_format,change_summary'],
            'version.content' => ['required', 'string', 'max:1000000'],
            'version.content_format' => [
                'required',
                'string',
                Rule::in(['markdown', 'plain_text']),
            ],
            'version.change_summary' => ['required', 'string', 'max:2000'],
        ])->validate()['version'];
    }

    /**
     * @throws JsonException
     */
    private function inputHash(
        GeneratedDocumentVersion $baseVersion,
        array $final,
    ): string {
        return hash('sha256', json_encode([
            'generator_key' => self::KEY,
            'generator_version' => self::VERSION,
            'source_resume_version_id' => $baseVersion->source_resume_version_id,
            'match_analysis_input_hash' => $baseVersion->matchAnalysis?->input_hash,
            'content_format' => $final['content_format'],
            'content_hash' => hash('sha256', $final['content']),
            'change_summary' => $final['change_summary'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
