<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ResolutionAttempt
 *
 * Matches the approved ResolutionAttempt contract shape plus qa_instructions
 * (Phase 7's own output) and, now that it exists, its own QA result if one
 * has been recorded (Phase 8).
 */
class ResolutionAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'outcome' => $this->outcome->value,
            'explanation' => $this->explanation,
            'qa_instructions' => $this->qa_instructions,
            'reproduction_attempts' => $this->reproduction_attempts,
            'reproduction_environment' => $this->reproduction_environment,
            'decision_rationale' => $this->decision_rationale,
            'recorded_by' => UserSummaryResource::make($this->recordedBy),
            'recorded_at' => $this->recorded_at,
            'qa_result' => $this->qaVerificationResult ? [
                'decision' => $this->qaVerificationResult->decision->value,
                'verification_notes' => $this->qaVerificationResult->verification_notes,
                'verifier' => UserSummaryResource::make($this->qaVerificationResult->verifier),
                'created_at' => $this->qaVerificationResult->created_at,
            ] : null,
        ];
    }
}
