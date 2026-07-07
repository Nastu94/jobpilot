<?php

namespace App\Services\Documents;

use App\Models\MatchAnalysis;
use App\Models\MatchFactor;
use App\Models\ResumeVersion;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class DeterministicTargetedResumeDraftBuilder
{
    public const KEY = 'deterministic_targeted_resume_draft';

    public const VERSION = '1.0.0';

    /**
     * @return array{
     *     content: string,
     *     input_hash: string,
     *     change_summary: string
     * }
     *
     * @throws JsonException
     */
    public function build(ResumeVersion $sourceResumeVersion, MatchAnalysis $analysis): array
    {
        $analysis->loadMissing(['jobPosting', 'factors.evidences']);
        $sourceText = (string) $sourceResumeVersion->extracted_text;

        if (trim($sourceText) === '') {
            throw new InvalidArgumentException('The source resume does not contain extracted text.');
        }

        $guidance = $analysis->factors
            ->map(fn (MatchFactor $factor): string => $this->guidanceLine($factor))
            ->implode("\n");

        if ($guidance === '') {
            $guidance = '- No matching factors are available.';
        }

        $content = implode("\n\n", [
            '# Targeted resume review draft',
            '> The source resume below is reproduced without rewriting. The matching notes are review guidance and are not part of the final resume.',
            '## Source resume (unchanged)',
            $sourceText,
            '---',
            '## Matching review notes (not part of the final resume)',
            $guidance,
        ]);

        return [
            'content' => $content,
            'input_hash' => $this->inputHash($sourceResumeVersion, $analysis),
            'change_summary' => 'Copied the source resume without rewriting claims and added separate deterministic matching notes.',
        ];
    }

    /**
     * @throws JsonException
     */
    private function inputHash(ResumeVersion $sourceResumeVersion, MatchAnalysis $analysis): string
    {
        $payload = [
            'generator_key' => self::KEY,
            'generator_version' => self::VERSION,
            'source_resume_checksum' => $sourceResumeVersion->checksum_sha256,
            'source_text_hash' => hash('sha256', (string) $sourceResumeVersion->extracted_text),
            'match_input_hash' => $analysis->input_hash,
            'match_ruleset' => [
                'key' => $analysis->ruleset_key,
                'version' => $analysis->ruleset_version,
            ],
            'factors' => $analysis->factors
                ->map(fn (MatchFactor $factor): array => [
                    'key' => $factor->key,
                    'label' => $factor->label,
                    'category' => $factor->category,
                    'weight_bps' => $factor->weight_bps,
                    'score_bps' => $factor->score_bps,
                    'contribution_bps' => $factor->contribution_bps,
                    'outcome' => $factor->outcome,
                    'explanation' => $factor->explanation,
                    'evidence' => $factor->evidences
                        ->map(fn ($evidence): array => [
                            'type' => $evidence->evidence_type,
                            'source_type' => $evidence->source_type,
                            'source_reference' => $evidence->source_reference,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function guidanceLine(MatchFactor $factor): string
    {
        $score = $factor->score_bps === null
            ? 'N/A'
            : number_format($factor->score_bps / 100, 2, '.', '').'%';
        $outcome = Str::upper((string) ($factor->outcome ?: 'unresolved'));
        $category = $factor->category ?: 'other';
        $explanation = trim((string) $factor->explanation);

        return sprintf(
            '- [%s] %s | type: %s | score: %s%s',
            $outcome,
            $factor->label,
            $category,
            $score,
            $explanation === '' ? '' : ' | '.$explanation,
        );
    }
}
