<?php

namespace Aurora\Livewire;

use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The assistant's chat, picking up where it left off: the open conversation is kept in
 * the session, so reloading or moving to another admin page resumes it instead of
 * starting over. "New conversation" starts a fresh one as before.
 *
 * Deleting a conversation from the history asks in Filament's own dialog, rendered here at
 * the top of the chat rather than inside the chat panel, so it covers the whole window.
 */
class AssistantChat extends CopilotChat implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const SESSION_KEY = 'assistant.conversation';

    public function mount(): void
    {
        parent::mount();

        // Only resumes a conversation of the signed-in user (loadConversation scopes it).
        if (filled($conversationId = session(self::SESSION_KEY))) {
            $this->loadConversation($conversationId);

            if ($this->conversationId === null) {
                session()->forget(self::SESSION_KEY);
            }
        }
    }

    /**
     * After every request, remember which conversation is open.
     */
    public function dehydrate(): void
    {
        $this->conversationId === null
            ? session()->forget(self::SESSION_KEY)
            : session([self::SESSION_KEY => $this->conversationId]);
    }

    /**
     * The package's Markdown export, named after the assistant: ai-assistant-conversation-….md.
     */
    public function exportConversation(): ?StreamedResponse
    {
        $response = parent::exportConversation();

        $response?->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'ai-assistant-conversation-'.now()->format('Y-m-d-His').'.md',
        ));

        return $response;
    }

    #[On('assistant-remove-conversation')]
    public function confirmRemoveConversation(string $conversationId): void
    {
        $this->mountAction('removeConversation', ['id' => $conversationId]);
    }

    public function removeConversationAction(): Action
    {
        return Action::make('removeConversation')
            ->requiresConfirmation()
            ->color('danger')
            ->modalIcon('phosphor-trash')
            ->modalHeading(__('Delete this conversation?'))
            ->modalDescription(__('The conversation and everything in it is removed for good.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->action(function (array $arguments): void {
                $this->deleteConversation((string) ($arguments['id'] ?? ''));
                $this->dispatch('copilot-refresh-sidebar');
            });
    }
}
