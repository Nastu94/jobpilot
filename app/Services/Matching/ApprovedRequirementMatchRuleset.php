<?php

namespace App\Services\Matching;

use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

class ApprovedRequirementMatchRuleset
{
    public const KEY = 'approved_requirement_match';

    public const VERSION = '1.0.0';

    public function profileData(Profile $profile, Collection $requirements): array
    {
        return [
            'skills' => $this->approvedLinks(
                table: 'profile_skill',
                profile: $profile,
                taxonomyKey: 'skill_id',
                ids: $requirements->pluck('skill_id'),
                approvedOnly: true,
            ),
            'software' => $this->approvedLinks(
                table: 'profile_software',
                profile: $profile,
                taxonomyKey: 'software_id',
                ids: $requirements->pluck('software_id'),
                approvedOnly: true,
            ),
            'languages' => $this->approvedLinks(
                table: 'profile_language',
                profile: $profile,
                taxonomyKey: 'language_id',
                ids: $requirements->pluck('language_id'),
            ),
        ];
    }

    public function evaluate(Collection $requirements, array $profileData): array
    {
        $factors = $requirements
            ->map(fn (JobPostingRequirement $requirement): array => $this->evaluateRequirement(
                $requirement,
                $profileData,
            ))
            ->values()
            ->all();
        $factors = $this->allocateWeights($factors);
        $scorableCount = collect($factors)->where('scorable', true)->count();
        $approvedCount = count($factors);

        return [
            'factors' => $factors,
            'score_bps' => $scorableCount > 0
                ? array_sum(array_column($factors, 'contribution_bps'))
                : null,
            'status' => match (true) {
                $scorableCount === 0 => 'insufficient_data',
                $scorableCount < $approvedCount => 'partial_coverage',
                default => 'completed',
            },
            'summary' => $this->summary($factors, $scorableCount, $approvedCount),
        ];
    }

    /**
     * @throws JsonException
     */
    public function inputHash(
        Profile $profile,
        JobPosting $jobPosting,
        Collection $requirements,
        array $profileData,
    ): string {
        $payload = [
            'ruleset_key' => self::KEY,
            'ruleset_version' => self::VERSION,
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
                'skill_id' => $this->nullableInt($requirement->skill_id),
                'software_id' => $this->nullableInt($requirement->software_id),
                'language_id' => $this->nullableInt($requirement->language_id),
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

    private function approvedLinks(
        string $table,
        Profile $profile,
        string $taxonomyKey,
        Collection $ids,
        bool $approvedOnly = false,
    ): Collection {
        $ids = $ids->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = DB::table($table)
            ->where('profile_id', $profile->getKey())
            ->whereIn($taxonomyKey, $ids->all());

        if ($approvedOnly) {
            $query->where('is_approved', true);
        }

        return $query->get()->keyBy(fn (stdClass $row): int => (int) $row->{$taxonomyKey});
    }

    private function evaluateRequirement(JobPostingRequirement $requirement, array $profileData): array
    {
        return match ($requirement->requirement_type) {
            'skill' => $this->evaluateSkillOrSoftware(
                $requirement,
                $requirement->skill_id,
                $requirement->skill?->name,
                $requirement->skill_id === null
                    ? null
                    : $profileData['skills']->get((int) $requirement->skill_id),
                'profile_skill',
            ),
            'software' => $this->evaluateSkillOrSoftware(
                $requirement,
                $requirement->software_id,
                $requirement->software?->name,
                $requirement->software_id === null
                    ? null
                    : $profileData['software']->get((int) $requirement->software_id),
                'profile_software',
            ),
            'language' => $this->evaluateLanguage(
                $requirement,
                $requirement->language?->name,
                $requirement->language_id === null
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
        mixed $taxonomyId,
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
                $requirement,
                0,
                'gap',
                'The profile does not contain an approved matching taxonomy entry.',
            );
        }

        $scores = [];
        $explanations = [];
        $minYears = $this->decimal($requirement->min_years);

        if ($minYears !== null && $minYears > 0) {
            $candidateYears = $this->decimal($link->years_experience);

            if ($candidateYears === null) {
                $scores[] = 0;
                $explanations[] = sprintf(
                    'The requirement asks for %.1f years, but the profile entry has no years value.',
                    $minYears,
                );
            } else {
                $scores[] = $candidateYears >= $minYears
                    ? 10000
                    : max(0, min(9999, (int) round(($candidateYears / $minYears) * 10000)));
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
            $scores[] = $candidateLevel !== null && $candidateLevel === $requiredLevel ? 10000 : 0;
            $explanations[] = match (true) {
                $candidateLevel === null => 'The approved profile entry has no proficiency level.',
                $candidateLevel === $requiredLevel => 'The profile proficiency exactly matches the requirement.',
                default => 'Generic proficiency labels differ; no ordering is inferred.',
            };
        }

        $score = $scores === [] ? 10000 : min($scores);

        return $this->scored(
            $requirement,
            $score,
            $score === 10000 ? 'matched' : ($score > 0 ? 'partial' : 'unverified'),
            $explanations === []
                ? 'The profile contains the approved matching taxonomy entry.'
                : implode(' ', $explanations),
            $sourceType,
            (int) $link->id,
            $taxonomyName,
            $this->skillOrSoftwareDetails($link),
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
                $requirement,
                0,
                'gap',
                'The profile does not contain the required language.',
            );
        }

        $requiredLevel = $this->normalizedLevel($requirement->proficiency_level);
        $candidateLevel = $this->normalizedLevel($link->proficiency_level);
        $isNative = (bool) $link->is_native;

        if ($requiredLevel === null || $isNative) {
            [$score, $outcome, $explanation] = [
                10000,
                'matched',
                $isNative
                    ? 'The profile marks this language as native.'
                    : 'The profile contains the required language.',
            ];
        } elseif ($candidateLevel === null) {
            [$score, $outcome, $explanation] = [
                0,
                'unverified',
                'The profile language has no proficiency level.',
            ];
        } elseif ($candidateLevel === $requiredLevel) {
            [$score, $outcome, $explanation] = [
                10000,
                'matched',
                'The profile language level exactly matches the requirement.',
            ];
        } else {
            $requiredRank = $this->cefrRank($requiredLevel);
            $candidateRank = $this->cefrRank($candidateLevel);

            if ($requiredRank !== null && $candidateRank !== null) {
                $meetsMinimum = $candidateRank >= $requiredRank;
                [$score, $outcome, $explanation] = [
                    $meetsMinimum ? 10000 : 0,
                    $meetsMinimum ? 'matched' : 'gap',
                    sprintf(
                        'The profile language level is %s and the minimum requirement is %s.',
                        $link->proficiency_level,
                        $requirement->proficiency_level,
                    ),
                ];
            } else {
                [$score, $outcome, $explanation] = [
                    0,
                    'unverified',
                    'The language levels differ and cannot be ordered deterministically.',
                ];
            }
        }

        return $this->scored(
            $requirement,
            $score,
            $outcome,
            $explanation,
            'profile_language',
            (int) $link->id,
            $taxonomyName,
            $this->languageDetails($link),
        );
    }

    private function scored(
        JobPostingRequirement $requirement,
        int $score,
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
            'score_bps' => max(0, min(10000, $score)),
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
                ? ($factor['requirement']->importance === 'required' ? 2 : 1)
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

            $units = $factor['requirement']->importance === 'required' ? 2 : 1;
            $weight = intdiv($units * 10000, $totalUnits);
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
            if ($factor['scorable']) {
                $factors[$index]['contribution_bps'] = intdiv(
                    $factors[$index]['weight_bps'] * $factor['score_bps'],
                    10000,
                );
            }
        }

        return $factors;
    }

    private function summary(array $factors, int $scorableCount, int $approvedCount): string
    {
        $counts = collect($factors)->countBy('outcome');
        $parts = [];

        foreach (['matched', 'partial', 'gap', 'unverified', 'unresolved', 'not_scored'] as $outcome) {
            $count = (int) $counts->get($outcome, 0);

            if ($count > 0) {
                $parts[] = $outcome.': '.$count;
            }
        }

        $prefix = $scorableCount === 0
            ? 'No approved requirement could be scored.'
            : sprintf(
                'Deterministic matching scored %d of %d approved requirements.',
                $scorableCount,
                $approvedCount,
            );

        return $parts === [] ? $prefix : $prefix.' '.implode(', ', $parts).'.';
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
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
