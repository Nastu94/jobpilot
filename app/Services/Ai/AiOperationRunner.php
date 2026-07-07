<?php

namespace App\Services\Ai;

use App\Data\Ai\AiOperationContext;
use App\Data\Ai\AiOperationResult;
use App\Data\Ai\AiRequest;
use App\Models\AiOperation;
use Throwable;

class AiOperationRunner
{
    public function __construct(
        private readonly AiGateway $gateway,
    ) {
    }

    public function run(
        AiRequest $request,
        AiOperationContext $context,
        ?string $providerKey = null,
    ): AiOperationResult {
        $provider = $this->gateway->provider($providerKey);
        $startedAt = now();
        $startedAtNs = hrtime(true);

        $operation = AiOperation::create([
            'profile_id' => $context->profileId,
            'job_posting_id' => $context->jobPostingId,
            'match_analysis_id' => $context->matchAnalysisId,
            'generated_document_version_id' => $context->generatedDocumentVersionId,
            'operation_type' => $request->operationType,
            'provider' => $provider->key(),
            'prompt_template_key' => $context->promptTemplateKey,
            'prompt_template_version' => $context->promptTemplateVersion,
            'status' => 'running',
            'request_hash' => $request->hash(),
            'payloads_stored' => false,
            'started_at' => $startedAt,
        ]);

        try {
            $response = $provider->generate($request);

            $operation->forceFill([
                'provider' => $response->provider,
                'model' => $response->model,
                'status' => 'completed',
                'response_hash' => $response->responseHash,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'duration_ms' => $response->durationMs
                    ?? intdiv(hrtime(true) - $startedAtNs, 1_000_000),
                'cost_micros' => $provider->isPaid() ? null : 0,
                'metadata' => $response->metadata,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            return new AiOperationResult(
                operation: $operation->refresh(),
                response: $response,
            );
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => 'failed',
                'duration_ms' => intdiv(hrtime(true) - $startedAtNs, 1_000_000),
                'cost_micros' => $provider->isPaid() ? null : 0,
                'error_code' => mb_substr(class_basename($exception), 0, 100),
                'error_message' => 'The AI operation failed before completion.',
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
