<?php

namespace App\Data\Ai;

final readonly class AiOperationContext
{
    public function __construct(
        public int $profileId,
        public ?int $jobPostingId = null,
        public ?int $matchAnalysisId = null,
        public ?int $generatedDocumentVersionId = null,
        public ?string $promptTemplateKey = null,
        public ?string $promptTemplateVersion = null,
    ) {
    }
}
