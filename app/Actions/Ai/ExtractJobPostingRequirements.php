<?php

namespace App\Actions\Ai;

use App\Data\Ai\AiOperationContext;
use App\Data\Ai\AiRequest;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Services\Ai\AiOperationRunner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Throwable;
use UnexpectedValueException;

class ExtractJobPostingRequirements
{
    private const TEMPLATE_KEY = 'job_requirement_extraction';

    private const TEMPLATE_VERSION = '1.0.0';

    public function __construct(
        private readonly AiOperationRunner $runner,
    ) {
    }

    public function execute(
        JobPosting $jobPosting,
        ?string $providerKey = null,
    ): Collection {
        $sourceText = trim((string) ($jobPosting->raw_content ?: $jobPosting->description));

        if ($sourceText === '') {
            throw new InvalidArgumentException('The job posting does not contain text to analyze.');
        }

        $result = $this->runner->run(
            request: new AiRequest(
                operationType: self::TEMPLATE_KEY,
                input: $sourceText,
                instruction: $this->instruction(),
                options: ['temperature' => 0],
            ),
            context: new AiOperationContext(
                profileId: $jobPosting->profile_id,
                jobPostingId: $jobPosting->id,
                promptTemplateKey: self::TEMPLATE_KEY,
                promptTemplateVersion: self::TEMPLATE_VERSION,
            ),
            providerKey: $providerKey,
        );

        try {
            $requirements = $this->validatedRequirements(
                content: $result->response->content,
                sourceText: $sourceText,
            );
        } catch (Throwable $exception) {
            $result->operation->forceFill([
                'status' => 'invalid_response',
                'error_code' => mb_substr(class_basename($exception), 0, 100),
                'error_message' => 'The AI response did not match the required structure.',
            ])->save();

            throw $exception;
        }

        return DB::transaction(function () use ($jobPosting, $requirements): Collection {
            JobPostingRequirement::query()
                ->where('job_posting_id', $jobPosting->id)
                ->where('source', 'ai')
                ->where('review_status', 'pending')
                ->delete();

            foreach ($requirements as $position => $requirement) {
                $jobPosting->requirements()->create([
                    'requirement_type' => $requirement['type'],
                    'importance' => $requirement['importance'],
                    'label' => $requirement['label'],
                    'normalized_label' => Str::lower(Str::squish(
                        $requirement['normalized_label'] ?? $requirement['label']
                    )),
                    'proficiency_level' => $requirement['proficiency_level'] ?? null,
                    'min_years' => $requirement['min_years'] ?? null,
                    'source' => 'ai',
                    'review_status' => 'pending',
                    'confidence_bps' => $requirement['confidence_bps'] ?? null,
                    'evidence' => $requirement['evidence'],
                    'position' => $position,
                ]);
            }

            return JobPostingRequirement::query()
                ->where('job_posting_id', $jobPosting->id)
                ->where('source', 'ai')
                ->where('review_status', 'pending')
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        });
    }

    private function validatedRequirements(string $content, string $sourceText): array
    {
        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The AI response is not valid JSON.', 0, $exception);
        }

        $validated = Validator::make($payload, [
            'requirements' => ['required', 'array', 'max:100'],
            'requirements.*' => [
                'array:type,importance,label,normalized_label,proficiency_level,min_years,confidence_bps,evidence',
            ],
            'requirements.*.type' => [
                'required',
                'string',
                Rule::in([
                    'skill',
                    'software',
                    'language',
                    'education',
                    'experience',
                    'location',
                    'employment',
                    'certification',
                    'other',
                ]),
            ],
            'requirements.*.importance' => [
                'required',
                'string',
                Rule::in(['required', 'preferred']),
            ],
            'requirements.*.label' => ['required', 'string', 'max:255'],
            'requirements.*.normalized_label' => ['nullable', 'string', 'max:255'],
            'requirements.*.proficiency_level' => ['nullable', 'string', 'max:50'],
            'requirements.*.min_years' => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'requirements.*.confidence_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'requirements.*.evidence' => ['required', 'string', 'max:2000'],
        ])->validate();

        foreach ($validated['requirements'] as $requirement) {
            $evidence = trim($requirement['evidence']);

            if ($evidence === '' || mb_stripos($sourceText, $evidence) === false) {
                throw new UnexpectedValueException(
                    'Every extracted requirement must reference evidence from the job posting.'
                );
            }
        }

        return $validated['requirements'];
    }

    private function instruction(): string
    {
        return <<<'TEXT'
Extract only explicit job requirements from the supplied posting.
Return JSON with one top-level key named requirements.
Each requirement must contain: type, importance, label, evidence.
Optional keys: normalized_label, proficiency_level, min_years, confidence_bps.
Evidence must be an exact excerpt copied from the supplied posting.
Do not infer or invent missing requirements.
TEXT;
    }
}
