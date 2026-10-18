<?php

namespace Aurora\Livewire;

use Aurora\Models\Asset;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\AssistantFiles;
use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Models\CopilotMessage;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
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
    use WithFileUploads;

    public const SESSION_KEY = 'assistant.conversation';

    /**
     * Pictures chosen in the composer, not sent yet.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $imageUploads = [];

    /**
     * Pictures added to the library, ready to go with the next message.
     *
     * @var array<int, array{id: string, title: string, url: ?string}>
     */
    public array $attachedImages = [];

    public function mount(): void
    {
        parent::mount();

        $this->claimUntenantedConversations();

        // Only resumes a conversation of the signed-in user (loadConversation scopes it).
        if (filled($conversationId = session(self::SESSION_KEY))) {
            $this->loadConversation($conversationId);

            if ($this->conversationId === null) {
                session()->forget(self::SESSION_KEY);
            }
        }
    }

    /**
     * Remember a conversation the stream just started, before its reply has finished.
     *
     * The editor reloads once a change has been saved. The id has to be in the session
     * before that, or the reloaded chat starts empty. A guessed id is ignored.
     */
    public function rememberConversation(string $conversationId): void
    {
        if ($this->conversationId !== null || blank($conversationId)) {
            return;
        }

        $user = Filament::auth()->user();
        $panelId = Filament::getCurrentPanel()?->getId();

        if ($user === null || $panelId === null) {
            return;
        }

        $exists = CopilotConversation::query()
            ->forPanel($panelId)
            ->forParticipant($user)
            ->forTenant(Filament::getTenant())
            ->whereKey($conversationId)
            ->exists();

        if ($exists) {
            $this->conversationId = $conversationId;
        }
    }

    /**
     * Chats opened before the stream knew its site were saved with none, so the history
     * could not list them. They belong to the site the person is in now.
     */
    protected function claimUntenantedConversations(): void
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();
        $panelId = Filament::getCurrentPanel()?->getId();

        if (! $tenant instanceof Site || $user === null || $panelId === null) {
            return;
        }

        CopilotConversation::query()
            ->forPanel($panelId)
            ->forParticipant($user)
            ->whereNull('tenant_type')
            ->whereNull('tenant_id')
            ->each(function (CopilotConversation $conversation) use ($tenant): void {
                $conversation->timestamps = false;
                $conversation->forceFill([
                    'tenant_type' => $tenant->getMorphClass(),
                    'tenant_id' => $tenant->getKey(),
                ])->saveQuietly();
            });
    }

    /**
     * Files chosen for the next message. They stay out of the media library until a page uses them.
     */
    public function updatedImageUploads(): void
    {
        if ($this->imageUploads === []) {
            return;
        }

        $user = Filament::auth()->user();

        if (! $user instanceof User || ! Gate::check('create', Asset::class)) {
            Notification::make()->title(__('You can’t add files.'))->danger()->send();
            $this->imageUploads = [];

            return;
        }

        foreach ($this->imageUploads as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (count($this->attachedImages) >= AssistantFiles::MAX) {
                Notification::make()->title(__('You can attach up to 4 files.'))->warning()->send();

                break;
            }

            if ($reason = AssistantFiles::rejection($file)) {
                Notification::make()->title($reason)->danger()->send();

                continue;
            }

            $this->attachedImages[] = AssistantFiles::store($file, $user);
        }

        $this->imageUploads = [];
    }

    public function detachImage(string $id): void
    {
        AssistantFiles::delete($id, Filament::auth()->user());

        $this->attachedImages = array_values(array_filter(
            $this->attachedImages,
            fn (array $image): bool => $image['id'] !== $id,
        ));
    }

    /**
     * Send the message, and any files waiting in the composer.
     */
    public function sendMessage(): void
    {
        $content = trim($this->message);
        $images = $this->attachedImages;

        if ($content === '' && $images === []) {
            return;
        }

        if ($content === '') {
            $content = __('Use the attached files.');
        }

        $this->message = '';
        $this->attachedImages = [];

        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
            'files' => $images,
        ];

        $this->dispatch('copilot-send-stream', [
            'message' => $content,
            'images' => array_column($images, 'id'),
            'conversationId' => $this->conversationId,
            'panelId' => Filament::getCurrentPanel()?->getId(),
            'streamUrl' => $this->getStreamUrl(),
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getConversationMessages($conversation): array
    {
        $messages = parent::getConversationMessages($conversation);

        $files = $conversation->messages()->get(['id', 'metadata'])->mapWithKeys(
            fn (CopilotMessage $message): array => [$message->id => $message->metadata['files'] ?? $message->metadata['images'] ?? []],
        );

        foreach ($messages as $index => $message) {
            $id = $message['id'] ?? null;

            if (is_string($id) && filled($files[$id] ?? null)) {
                $messages[$index]['files'] = $files[$id];
            }
        }

        return $messages;
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
