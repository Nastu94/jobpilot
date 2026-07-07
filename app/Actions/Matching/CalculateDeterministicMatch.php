<?php

namespace App\Actions\Matching;

use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\MatchAnalysis;
use App\Models\Profile;
use App\Services\Matching\ApprovedRequirementMatchRuleset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalculateDeterministicMatch
{
    public const RULESET_KEY = ApprovedRequirementMatchRuleset::KEY;

    public const RULESET_VERSION = ApprovedRequirementMatchRuleset::VERSION;

    public function __construct(
        private readonly ApprovedRequirementMatchRuleset $ruleset,
    ) {
    }

    public function execute(Profile $profile, JobPosting $jobPosting): MatchAnalysis
    {
        if ((int) $jobPosting->profile_id !== (int) $profile->getKey()) {
            throw new AuthorizationException('The profile does not own this job posting.');
        }

        $requirements = $jobPosting->approvedRequirements()
            ->with(['skill', 'software', 'language'])
            ->get();
        $profileData = $this->ruleset->profileData($profile, $requirements);
        $result = $this->ruleset->evaluate($requirements, $profileData);
        $inputHash = $this->ruleset->inputHash(
            $profile,
            $jobPosting,
            $requirements,
            $profileData,
        );

        return DB::transaction(function () use (
            $profile,
            $jobPosting,
            $result,
            $inputHash,
        ): MatchAnalysis {
            $analysis = MatchAnalysis::create([
                'profile_id' => $profile->getKey(),
                'job_posting_id' => $jobPosting->getKey(),
                'ruleset_key' => self::RULESET_KEY,
                'ruleset_version' => self::RULESET_VERSION,
                'status' => $result['status'],
                'score_bps' => $result['score_bps'],
                'input_hash' => $inputHash,
                'calculated_at' => now(),
                'summary' => $result['summary'],
            ]);

            foreach ($result['factors'] as $position => $evaluated) {
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
}
