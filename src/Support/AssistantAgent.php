<?php

namespace Aurora\Support;

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * The in-panel assistant, with the files the person attached to this message.
 *
 * The chat package's stream only sends text. Files travel on the same request
 * (AssistantFiles) and are added here, before the model is asked.
 */
class AssistantAgent extends CopilotAgent
{
    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        if (is_string($prompt) && $attachments === []) {
            [$prompt, $attachments] = AssistantFiles::attach(request()->input('images'), $prompt, $this->user);
        }

        return parent::stream($prompt, $attachments, $provider, $model, $timeout);
    }
}
