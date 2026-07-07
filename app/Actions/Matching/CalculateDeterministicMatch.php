<?php

namespace App\Actions\Matching;

use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\MatchAnalysis;
use App\Models\Profile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

class CalculateDeterministicMatch
{
    public const RULESET_KEY = 'approved_requirement_match';

    public const RULESET_VERSION = '1.0.0';

    public function execute(Profile $profile, JobPosting $jobPosting): MatchAnalysis
    {
        if ((int) $jobPosting->profile_id !== (int) $profile->getKey()) {
            throw new AuthorizationException('The profile does not own this job posting.');
        }

        $requirements = $jobPosting->approvedRequirements()
            ->with(['skill', 'software', 'language'])
            ->get();
        $profileData = $this->profileData($profile, $requirements);
        $factors = $requirements
            ->map(fn (JobPostingRequirement $requirement): array => $this->evaluateRequirement(
                $requirement,
                $profileData,
            ))
            ->values()
            ->all();
        $factors = $this->allocateWeights($factors);
        $hasScorableRequirements = collect($factors)->contains('scorable', true);
        $scoreBps = $hasScorableRequirements
            ? array_sum(array_column($factors, 'contribution_bps'))
            : null;
        $inputHash = $this->inputHash($profile, $jobPosting, $requirements, $profileData);

        return DB::transaction(function () use (
            $profile,
            $jobPosting,
            $factors,
            $hasScorableRequirements,
            $scoreBps,
            $inputHash,
        ): MatchAnalysis {
            $analysis = MatchAnalysis::create([
                'profile_id' => $profile->getKey(),
                'job_posting_id' => $jobPosting->getKey(),
                'ruleset_key' => self::RULESET_KEY,
                'ruleset_version' => self::RULESET_VERSION,
                'status' => $hasScorableRequirements ? 'completed' : 'insufficient_data',
                'score_bps' => $scoreBps,
                'input_hash' => $inputHash,
                'calculated_at' => now(),
                'summary' => $this->summary($factors, $hasScorableRequirements),
            ]);

            foreach ($factors as $position => $evaluated) {
                /** @var JobPostingRequirement $requirement */
                $requirement = $evaluated['requirement'];
                $factor = $analysis->factors()->create([
                    'key' => 'requirement:'.$requirement->getKey(),
                    'label' => $requirement->label,
                    'category' => $requirement->requirement_type,
                    'weight_bps' => $evaluated['weight_bps'],
                    'score_bps' => $evaluated['score_bps'],
                    'contribution_bps' => $evaluated['contribution_bps'],
                    'outcome' => $evaluated['outcome'],
                    'explanation' => $evaluated['explanation'],
                    'position' => $position,
                ]);

                $factor->evidences()->create([
                    'evidence_type' => 'requirement',
                    'label' => Str::limit($requirement->label, 255, ''),
                    'source_type' => 'job_posting_requirement',
                    'source_id' => $requirement->getKey(),
                    'source_reference' => Str::limit($requirement->label, 255, ''),
                    'details' => $requirement->evidence,
                    'position' => 0,
                ]);

                if ($evaluated['profile_source_id'] !== null) {
                    $factor->evidences()->create([
                        'evidence_type' => 'profile',
                        'label' => Str::limit($evaluated['profile_source_reference'], 255, ''),
                        'source_type' => $evaluated['profile_source_type'],
                        'source_id' => $evaluated['profile_source_id'],
                        'source_reference' => Str::limit(
                            $evaluated['profile_source_reference'],
                            255,
                            '',
                        ),
                        'details' => $evaluated['profile_details'],
                        'position' => 1,
                    ]);
                }
            }

            return $analysis->fresh(['factors.evidences']);
        });
    }

    private function profileData(Profile $profile, Collection $requirements): array
    {
        $skillIds = $requirements->pluck('skill_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        $softwareIds = $requirements->pluck('software_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        $languageIds = $requirements->pluck('language_id')->filter()->map(fn ($id): int => (int) $id)->unique();

        return [
            'skills' => $skillIds->isEmpty()
                ? collect()
                : DB::table('profile_skill')
                    ->where('profile_id', $profile->getKey())
                    ->whereIn('skill_id', $skillIds->all())
                    ->where('is_approved', true)
                    ->get()
                    ->keyBy(fn (stdClass $row): int => (int) $row->skill_id),
            'software' => $softwareIds->isEmpty()
                ? collect()
                : DB::table('profile_software')
                    ->where('profile_id', $profile->getKey())
                    ->whereIn('software_id', $softwareIds->all())
                    ->where('is_approved', true)
                    ->get()
                    ->keyBy(fn (stdClass $row): int => (int) $row->software_id),
            'languages' => $languageIds->isEmpty()
                ? collect()
                : DB::table('profile_language')
                    ->where('profile_id', $profile->getKey())
                    ->whereIn('language_id', $languageIds->all())
                    ->get()
                    ->keyBy(fn (stdClass $row): int => (int) $row->language_id),
        ];
    }

    private function evaluateRequirement(JobPostingRequirement $requirement, array $profileData): array
    {
        return match ($requirement->requirement_type) {
            'skill' => $this->evaluateSkillOrSoftware(
                requirement: $requirement,
                taxonomyId: $requirement->skill_id,
                taxonomyName: $requirement->skill?->name,
                link: $requirement->skill_id === null
                    ? null
                    : $profileData['skills']->get((int) $requirement->skill_id),
                sourceType: 'profile_skill',
            ),
            'software' => $this->evaluateSkillOrSoftware(
                requirement: $requirement,
                taxonomyId: $requirement->software_id,
                taxonomyName: $requirement->software?->name,
                link: $requirement->software_id === null
                    ? null
                    : $profileData['software']->get((int) $requirement->software_id),
                sourceType: 'profile_software',
            ),
            'language' => $this->evaluateLanguage(
                requirement: $requirement,
                taxonomyName: $requirement->language?->name,
                link: $requirement->language_id === null
                    ? null
                    : $profileData['languages']->get((int) $requirement->language_id),
            ),
            default => $this->unscored(
                $requirement,
                'not_scored',
                'This approved requirement type is not scored by ruleset 1.0.0.',
            ),
        };
    }

    private function evaluateSkillOrSoftware(
        JobPostingRequirement $requirement,
        ?int $taxonomyId,
        ?string $taxonomyName,
        ?stdClass $link,
        string $sourceType,
    ): array {
        if ($taxonomyId === null || $taxonomyName === null) {
            return $this->unscored(
                $requirement,
                'unresolved',
                'The approved requirement is not linked to a taxonomy entry.',
            );
        }

        if ($link === null) {
            return $this->scored(
                requirement: $requirement,
                scoreBps: 0,
                outcome: 'gap',
                explanation: 'The profile does not contain an approved matching taxonomy entry.',
            );
        }

        $constraintScores = [];
        $explanations = [];
        $minYears = $this->decimal($requirement->min_years);

        if ($minYears !== null && $minYears > 0) {
            $candidateYears = $this->decimal($link->years_experience);

            if ($candidateYears === null) {
                $constraintScores[] = 0;
                $explanations[] = sprintf(
                    'The requirement asks for %.1f years, but the approved profile entry has no years value.',
                    $minYears,
                );
            } elseif ($candidateYears >= $minYears) {
                $constraintScores[] = 10000;
                $explanations[] = sprintf(
                    'The profile records %.1f years against %.1f required.',
                    $candidateYears,
                    $minYears,
                );
            } else {
                $constraintScores[] = max(0, min(9999, (int) round(
                    ($candidateYears / $minYears) * 10000,
                )));
                $explanations[] = sprintf(
                    'The profile records %.1f years against %.1f required.',
                    $candidateYears,
                    $minYears,
                );
            }
        }

        $requiredLevel = $this->normalizedLevel($requirement->proficiency_level);

        if ($requiredLevel !== null) {
            $candidateLevel = $this->normalizedLevel($link->proficiency_level);

            if ($candidateLevel === null) {
                $constraintScores[] = 0;
                $explanations[] = 'The approved profile entry has no proficiency level.';
            } elseif ($candidateLevel === $requiredLevel) {
                $constraintScores[] = 10000;
                $explanations[] = 'The profile proficiency exactly matches the reviewed requirement.';
            } else {
                $constraintScores[] = 0;
                $explanations[] = 'Generic proficiency labels differ and ruleset 1.0.0 does not infer an ordering.';
            }
        }

        $scoreBps = $constraintScores === [] ? 10000 : min($constraintScores);
        $outcome = match (true) {
            $scoreBps === 10000 => 'matched',
            $scoreBps > 0 => 'partial',
            default => 'unverified',
        };
        $explanation = $explanations === []
            ? 'The profile contains the approved matching taxonomy entry.'
            : implode(' ', $explanations);

        return $this->scored(
            requirement: $requirement,
            scoreBps: $scoreBps,
            outcome: $outcome,
            explanation: $explanation,
            profileSourceType: $sourceType,
            profileSourceId: (int) $link->id,
            profileSourceReference: $taxonomyName,
            profileDetails: $this->skillOrSoftwareDetails($link),
        );
    }

    private function evaluateLanguage(
        JobPostingRequirement $requirement,
        ?string $taxonomyName,
        ?stdClass $link,
    ): array {
        if ($requirement->language_id === null || $taxonomyName === null) {
            return $this->unscored(
                $requirement,
                'unresolved',
                'The approved language requirement is not linked to a taxonomy entry.',
            );
        }

        if ($link === null) {
            return $this->scored(
                requirement: $requirement,
                scoreBps: 0,
                outcome: 'gap',
                explanation: 'The profile does not contain the required language.',
            );
        }

        $requiredLevel = $this->normalizedLevel($requirement->proficiency_level);
        $candidateLevel = $this->normalizedLevel($link->proficiency_level);
        $isNative = (bool) $link->is_native;

        if ($requiredLevel === null || $isNative) {
            $scoreBps = 10000;
            $outcome = 'matched';
            $explanation = $isNative
                ? 'The profile marks this language as native.'
                : 'The profile contains the required language.';
        } elseif ($candidateLevel === null) {
            $scoreBps = 0;
            $outcome = 'unverified';
            $explanation = 'The profile language has no proficiency level.';
        } elseif ($candidateLevel === $requiredLevel) {
            $scoreBps = 10000;
            $outcome = 'matched';
            $explanation = 'The profile language level exactly matches the reviewed requirement.';
        } else {
            $requiredRank = $this->cefrRank($requiredLevel);
            $candidateRank = $this->cefrRank($candidateLevel);

            if ($requiredRank !== null && $candidateRank !== null) {
                $scoreBps = $candidateRank >= $requiredRank
                    ? 10000
                    : (int) round(($candidateRank / $requiredRank) * 10000);
                $outcome = $scoreBps === 10000 ? 'matched' : 'partial';
                $explanation = sprintf(
                    'The profile language level is %s and the reviewed requirement is %s.',
                    $link->proficiency_level,
                    $requirement->proficiency_level,
                );
            } else {
                $scoreBps = 0;
                $outcome = 'unverified';
                $explanation = 'The language levels differ and cannot be ordered deterministically.';
            }
        }

        return $this->scored(
            requirement: $requirement,
            scoreBps: $scoreBps,
            outcome: $outcome,
            explanation: $explanation,
            profileSourceType: 'profile_language',
            profileSourceId: (int) $link->id,
            profileSourceReference: $taxonomyName,
            profileDetails: $this->languageDetails($link),
        );
    }

    private function scored(
        JobPostingRequirement $requirement,
        int $scoreBps,
        string $outcome,
        string $explanation,
        ?string $profileSourceType = null,
        ?int $profileSourceId = null,
        ?string $profileSourceReference = null,
        ?string $profileDetails = null,
    ): array {
        return [
            'requirement' => $requirement,
            'scorable' => true,
            'score_bps' => max(0, min(10000, $scoreBps)),
            'outcome' => $outcome,
            'explanation' => $explanation,
            'profile_source_type' => $profileSourceType,
            'profile_source_id' => $profileSourceId,
            'profile_source_reference' => $profileSourceReference,
            'profile_details' => $profileDetails,
            'weight_bps' => 0,
            'contribution_bps' => 0,
        ];
    }

    private function unscored(
        JobPostingRequirement $requirement,
        string $outcome,
        string $explanation,
    ): array {
        return [
            'requirement' => $requirement,
            'scorable' => false,
            'score_bps' => null,
            'outcome' => $outcome,
            'explanation' => $explanation,
            'profile_source_type' => null,
            'profile_source_id' => null,
            'profile_source_reference' => null,
            'profile_details' => null,
            'weight_bps' => 0,
            'contribution_bps' => 0,
        ];
    }

    private function allocateWeights(array $factors): array
    {
        $totalUnits = array_sum(array_map(
            fn (array $factor): int => $factor['scorable']
                ? $this->importanceUnits($factor['requirement']->importance)
                : 0,
            $factors,
        ));

        if ($totalUnits === 0) {
            return $factors;
        }

        $allocated = 0;

        foreach ($factors as $index => $factor) {
            if (! $factor['scorable']) {
                continue;
            }

            $weight = intdiv(
                $this->importanceUnits($factor['requirement']->importance) * 10000,
                $totalUnits,
            );
            $factors[$index]['weight_bps'] = $weight;
            $allocated += $weight;
        }

        $remainder = 10000 - $allocated;

        foreach ($factors as $index => $factor) {
            if ($remainder === 0) {
                break;
            }

            if ($factor['scorable']) {
                $factors[$index]['weight_bps']++;
                $remainder--;
            }
        }

        foreach ($factors as $index => $factor) {
            if (! $factor['scorable']) {
                continue;
            }

            $factors[$index]['contribution_bps'] = intdiv(
                $factors[$index]['weight_bps'] * $factor['score_bps'],
                10000,
            );
        }

        return $factors;
    }

    private function importanceUnits(string $importance): int
    {
        return $importance === 'required' ? 2 : 1;
    }

    /**
     * @throws JsonException
     */
    private function inputHash(
        Profile $profile,
        JobPosting $jobPosting,
        Collection $requirements,
        array $profileData,
    ): string {
        $payload = [
            'ruleset_key' => self::RULESET_KEY,
            'ruleset_version' => self::RULESET_VERSION,
            'profile_id' => (int) $profile->getKey(),
            'job_posting_id' => (int) $jobPosting->getKey(),
            'requirements' => $requirements->map(fn (JobPostingRequirement $requirement): array => [
                'id' => (int) $requirement->getKey(),
                'type' => $requirement->requirement_type,
                'importance' => $requirement->importance,
                'label' => $requirement->label,
                'normalized_label' => $requirement->normalized_label,
                'proficiency_level' => $requirement->proficiency_level,
                'min_years' => $this->decimalString($requirement->min_years),
                'skill_id' => $requirement->skill_id === null ? null : (int) $requirement->skill_id,
                'software_id' => $requirement->software_id === null ? null : (int) $requirement->software_id,
                'language_id' => $requirement->language_id === null ? null : (int) $requirement->language_id,
                'evidence' => $requirement->evidence,
                'position' => (int) $requirement->position,
            ])->values()->all(),
            'profile_data' => [
                'skills' => $this->profileRowsForHash($profileData['skills'], 'skill_id'),
                'software' => $this->profileRowsForHash($profileData['software'], 'software_id'),
                'languages' => $this->languageRowsForHash($profileData['languages']),
            ],
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function profileRowsForHash(Collection $rows, string $taxonomyKey): array
    {
        return $rows
            ->sortBy(fn (stdClass $row): int => (int) $row->{$taxonomyKey})
            ->map(fn (stdClass $row): array => [
                $taxonomyKey => (int) $row->{$taxonomyKey},
                'proficiency_level' => $row->proficiency_level,
                'years_experience' => $this->decimalString($row->years_experience),
            ])
            ->values()
            ->all();
    }

    private function languageRowsForHash(Collection $rows): array
    {
        return $rows
            ->sortBy(fn (stdClass $row): int => (int) $row->language_id)
            ->map(fn (stdClass $row): array => [
                'language_id' => (int) $row->language_id,
                'proficiency_level' => $row->proficiency_level,
                'is_native' => (bool) $row->is_native,
            ])
            ->values()
            ->all();
    }

    private function summary(array $factors, bool $hasScorableRequirements): string
    {
        $counts = collect($factors)->countBy('outcome');
        $parts = [];

        foreach (['matched', 'partial', 'gap', 'unverified', 'unresolved', 'not_scored'] as $outcome) {
            $count = (int) $counts->get($outcome, 0);

            if ($count > 0) {
                $parts[] = $outcome.': '.$count;
            }
        }

        $prefix = $hasScorableRequirements
            ? 'Deterministic matching completed.'
            : 'No approved requirement could be scored.';

        return $parts === [] ? $prefix : $prefix.' '.implode(', ', $parts).'.';
    }

    private function skillOrSoftwareDetails(stdClass $link): string
    {
        $parts = [];

        if ($link->proficiency_level !== null) {
            $parts[] = 'Proficiency: '.$link->proficiency_level;
        }

        if ($link->years_experience !== null) {
            $parts[] = 'Years: '.$this->decimalString($link->years_experience);
        }

        return $parts === [] ? 'Approved profile taxonomy entry.' : implode('; ', $parts).'.';
    }

    private function languageDetails(stdClass $link): string
    {
        $parts = [];

        if ($link->proficiency_level !== null) {
            $parts[] = 'Proficiency: '.$link->proficiency_level;
        }

        $parts[] = 'Native: '.((bool) $link->is_native ? 'yes' : 'no');

        return implode('; ', $parts).'.';
    }

    private function normalizedLevel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::lower(Str::squish($value));

        return $value === '' ? null : $value;
    }

    private function cefrRank(string $level): ?int
    {
        return [
            'a1' => 1,
            'a2' => 2,
            'b1' => 3,
            'b2' => 4,
            'c1' => 5,
            'c2' => 6,
        ][$level] ?? null;
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function decimalString(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 1, '.', '');
    }
}
