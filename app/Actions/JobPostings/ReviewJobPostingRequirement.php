<?php

namespace App\Actions\JobPostings;

use App\Models\JobPostingRequirement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewJobPostingRequirement
{
    private const REQUIREMENT_TYPES = [
        'skill',
        'software',
        'language',
        'education',
        'experience',
        'location',
        'employment',
        'certification',
        'other',
    ];

    public function execute(
        JobPostingRequirement $requirement,
        User $reviewer,
        array $input,
    ): JobPostingRequirement {
        return DB::transaction(function () use ($requirement, $reviewer, $input): JobPostingRequirement {
            $requirement = JobPostingRequirement::query()
                ->with('jobPosting.profile')
                ->lockForUpdate()
                ->findOrFail($requirement->getKey());

            if ((int) $requirement->jobPosting->profile->user_id !== (int) $reviewer->getKey()) {
                throw new AuthorizationException('The reviewer does not own this job posting.');
            }

            if ($requirement->review_status !== 'pending') {
                throw ValidationException::withMessages([
                    'decision' => 'Only pending requirements can be reviewed.',
                ]);
            }

            $review = $this->validatedReview($requirement, $input);
            $this->ensureAssociationsAreCoherent($review);
            $this->preserveAiProposal($requirement);

            $requirement->forceFill([
                'requirement_type' => $review['requirement_type'],
                'importance' => $review['importance'],
                'label' => Str::squish($review['label']),
                'normalized_label' => Str::lower(Str::squish($review['label'])),
                'proficiency_level' => $this->nullableSquished($review['proficiency_level']),
                'min_years' => $review['min_years'],
                'skill_id' => $review['skill_id'],
                'software_id' => $review['software_id'],
                'language_id' => $review['language_id'],
                'review_status' => $review['decision'],
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $this->nullableSquished($review['review_notes']),
            ])->save();

            return $requirement->fresh([
                'jobPosting',
                'skill',
                'software',
                'language',
                'reviewedBy',
            ]);
        });
    }

    private function validatedReview(JobPostingRequirement $requirement, array $input): array
    {
        $rejecting = ($input['decision'] ?? null) === 'rejected';
        $associationDefaults = $rejecting
            ? ['skill_id' => null, 'software_id' => null, 'language_id' => null]
            : [
                'skill_id' => $requirement->skill_id,
                'software_id' => $requirement->software_id,
                'language_id' => $requirement->language_id,
            ];

        $review = array_merge([
            'decision' => null,
            'requirement_type' => $requirement->requirement_type,
            'importance' => $requirement->importance,
            'label' => $requirement->label,
            'proficiency_level' => $requirement->proficiency_level,
            'min_years' => $requirement->min_years,
            'review_notes' => null,
        ], $associationDefaults, $input);

        return Validator::make(['review' => $review], [
            'review' => [
                'required',
                'array:decision,requirement_type,importance,label,proficiency_level,min_years,skill_id,software_id,language_id,review_notes',
            ],
            'review.decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'review.requirement_type' => ['required', 'string', Rule::in(self::REQUIREMENT_TYPES)],
            'review.importance' => ['required', 'string', Rule::in(['required', 'preferred'])],
            'review.label' => ['required', 'string', 'max:255'],
            'review.proficiency_level' => ['nullable', 'string', 'max:50'],
            'review.min_years' => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'review.skill_id' => ['nullable', 'integer', Rule::exists('skills', 'id')],
            'review.software_id' => ['nullable', 'integer', Rule::exists('software', 'id')],
            'review.language_id' => ['nullable', 'integer', Rule::exists('languages', 'id')],
            'review.review_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate()['review'];
    }

    private function ensureAssociationsAreCoherent(array $review): void
    {
        foreach (['skill_id', 'software_id', 'language_id'] as $association) {
            if ($review['decision'] === 'rejected' && $review[$association] !== null) {
                throw ValidationException::withMessages([
                    $association => 'Rejected requirements cannot be linked to a taxonomy entry.',
                ]);
            }
        }

        $allowedAssociation = match ($review['requirement_type']) {
            'skill' => 'skill_id',
            'software' => 'software_id',
            'language' => 'language_id',
            default => null,
        };

        foreach (['skill_id', 'software_id', 'language_id'] as $association) {
            if ($review[$association] !== null && $association !== $allowedAssociation) {
                throw ValidationException::withMessages([
                    $association => sprintf(
                        'The %s association is not valid for a %s requirement.',
                        $association,
                        $review['requirement_type'],
                    ),
                ]);
            }
        }
    }

    private function preserveAiProposal(JobPostingRequirement $requirement): void
    {
        if ($requirement->source !== 'ai') {
            return;
        }

        $requirement->forceFill([
            'proposed_requirement_type' => $requirement->proposed_requirement_type
                ?? $requirement->requirement_type,
            'proposed_importance' => $requirement->proposed_importance
                ?? $requirement->importance,
            'proposed_label' => $requirement->proposed_label
                ?? $requirement->label,
            'proposed_normalized_label' => $requirement->proposed_normalized_label
                ?? $requirement->normalized_label,
            'proposed_proficiency_level' => $requirement->proposed_proficiency_level
                ?? $requirement->proficiency_level,
            'proposed_min_years' => $requirement->proposed_min_years
                ?? $requirement->min_years,
        ]);
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
