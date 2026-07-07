<?php

namespace App\Actions\Documents;

use App\Models\GeneratedDocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewGeneratedDocumentVersion
{
    public function execute(
        GeneratedDocumentVersion $version,
        User $reviewer,
        array $input,
    ): GeneratedDocumentVersion {
        return DB::transaction(function () use ($version, $reviewer, $input): GeneratedDocumentVersion {
            $version = GeneratedDocumentVersion::query()
                ->with('generatedDocument.profile')
                ->lockForUpdate()
                ->findOrFail($version->getKey());

            if ((int) $version->generatedDocument->profile->user_id !== (int) $reviewer->getKey()) {
                throw new AuthorizationException('The reviewer does not own this generated document.');
            }

            if ($version->review_status !== 'pending') {
                throw ValidationException::withMessages([
                    'decision' => 'Only pending document versions can be reviewed.',
                ]);
            }

            $review = $this->validatedReview($input);

            if ($review['decision'] === 'approved') {
                $this->ensureVersionCanBeApproved($version);
            }

            $version->forceFill([
                'review_status' => $review['decision'],
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $this->nullableSquished($review['review_notes']),
            ])->save();

            $this->updateDocumentStatus($version);

            return $version->fresh([
                'generatedDocument',
                'sourceResumeVersion',
                'matchAnalysis',
                'reviewedBy',
            ]);
        });
    }

    private function validatedReview(array $input): array
    {
        $rejecting = ($input['decision'] ?? null) === 'rejected';

        return Validator::make(['review' => $input], [
            'review' => ['required', 'array:decision,review_notes'],
            'review.decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'review.review_notes' => [
                $rejecting ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
        ])->validate()['review'];
    }

    private function ensureVersionCanBeApproved(GeneratedDocumentVersion $version): void
    {
        if ($version->contains_unverified_claims) {
            throw ValidationException::withMessages([
                'decision' => 'A version containing unverified claims cannot be approved.',
            ]);
        }

        if (
            trim((string) $version->content) === ''
            && trim((string) $version->storage_path) === ''
        ) {
            throw ValidationException::withMessages([
                'decision' => 'A version without content or a stored file cannot be approved.',
            ]);
        }
    }

    private function updateDocumentStatus(GeneratedDocumentVersion $version): void
    {
        if ($version->review_status === 'approved') {
            $version->generatedDocument->forceFill(['status' => 'ready'])->save();

            return;
        }

        $hasApprovedVersion = GeneratedDocumentVersion::query()
            ->where('generated_document_id', $version->generated_document_id)
            ->where('review_status', 'approved')
            ->exists();

        $version->generatedDocument
            ->forceFill(['status' => $hasApprovedVersion ? 'ready' : 'draft'])
            ->save();
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
