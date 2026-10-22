<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\SubmissionStatus;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Notifications\FormSubmitted;
use Aurora\Support\FormFields;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

class FormsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function answers(array $overrides = []): array
    {
        return [
            'name' => 'Kari',
            'email' => 'kari@example.com',
            'budget' => '€10–50k',
            'message' => 'Hello!',
            'consent' => '1',
            '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
            ...$overrides,
        ];
    }

    public function test_a_form_block_renders_on_a_page(): void
    {
        $form = Form::factory()->create();
        Page::factory()->homepage()->create(['blocks' => [['id' => 'f', 'type' => 'form', 'data' => ['form_id' => $form->id]]]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('action="'.route('forms.submit', $form).'"', false)
            ->assertSee('name="website"', false)
            ->assertSee('Budget');
    }

    public function test_a_valid_submission_is_stored_and_emailed(): void
    {
        Notification::fake();
        $form = Form::factory()->create();

        $this->from('/contact')->post(route('forms.submit', $form), $this->answers())
            ->assertRedirect('/contact#form-'.$form->id)
            ->assertSessionHas('form_'.$form->id.'.sent');

        $submission = $form->submissions()->sole();
        $this->assertSame(SubmissionStatus::New, $submission->status);
        $this->assertSame('Kari', $submission->data['name']['value']);
        $this->assertSame('Yes', $submission->data['consent']['value']);
        Notification::assertSentTo(new AnonymousNotifiable, FormSubmitted::class);
    }

    public function test_invalid_answers_come_back_with_errors(): void
    {
        $form = Form::factory()->create();

        $this->from('/contact')->post(route('forms.submit', $form), $this->answers(['email' => 'nope', 'budget' => 'A million', 'consent' => null]))
            ->assertSessionHasErrors(['email', 'budget', 'consent'], errorBag: 'form_'.$form->id);

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_bots_are_quietly_marked_as_spam_and_not_emailed(): void
    {
        Notification::fake();
        $form = Form::factory()->create();

        $this->post(route('forms.submit', $form), $this->answers(['website' => 'http://spam.example']));
        $this->post(route('forms.submit', $form), $this->answers(['_started' => Crypt::encryptString((string) now()->timestamp)]));

        $this->assertSame(2, $form->submissions()->where('status', SubmissionStatus::Spam)->count());
        Notification::assertNothingSent();
    }

    public function test_inactive_forms_do_not_accept_submissions(): void
    {
        $form = Form::factory()->create(['is_active' => false]);

        $this->post(route('forms.submit', $form), $this->answers())->assertNotFound();
    }

    public function test_editors_triage_submissions_and_opening_one_marks_it_read(): void
    {
        $editor = $this->userWithRole('Editor');
        $form = Form::factory()->create();
        $submission = $form->submissions()->create(['data' => [
            'name' => ['label' => 'Name', 'value' => 'Kari'],
            'email' => ['label' => 'Email', 'value' => 'kari@example.com'],
        ]]);

        // Reading it lowers the "new" badge in the sidebar, so the sidebar redraws.
        Livewire::actingAs($editor)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
            ->assertCanSeeTableRecords([$submission])
            ->mountTableAction('view', $submission)
            ->assertDispatched('refresh-sidebar');

        $this->assertSame(SubmissionStatus::Read, $submission->refresh()->status);
        $this->assertSame('kari@example.com', $submission->replyAddress());
    }

    public function test_authors_cannot_see_submissions(): void
    {
        $this->assertFalse($this->userWithRole('Author')->can('viewAny', Form::class));
    }

    public function test_deleting_a_form_warns_about_the_live_pages_showing_it(): void
    {
        $form = Form::factory()->create();
        Page::factory()->published()->create(['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'data' => ['form_id' => $form->id]]]]);
        Page::factory()->create(['title' => 'Draft', 'blocks' => [['id' => 'f', 'type' => 'form', 'data' => ['form_id' => $form->id]]]]);

        $this->assertSame(['Contact'], $form->livePages()->pluck('title')->all());
        $this->assertStringStartsWith('It is on a live page: “Contact”.', FormResource::deleteDescription($form));

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditForm::class, ['record' => $form->getRouteKey()])
            ->callAction('delete');

        $this->assertTrue($form->fresh()->trashed());
    }

    public function test_uploaded_files_are_private_and_only_safe_types_are_taken(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $form = Form::factory()->create(['fields' => [
            ['type' => 'text', 'data' => ['label' => 'Name', 'name' => 'name', 'required' => true]],
            ['type' => 'file', 'data' => ['label' => 'CV', 'name' => 'cv', 'required' => true]],
        ]]);
        $answers = fn (UploadedFile $file): array => ['name' => 'Kari', 'cv' => $file, '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp)];

        $this->from('/contact')->post(route('forms.submit', $form), $answers(UploadedFile::fake()->createWithContent('cv.html', '<script>alert(1)</script>')))
            ->assertSessionHasErrors('cv', null, 'form_'.$form->id);

        $this->from('/contact')->post(route('forms.submit', $form), $answers(UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')))
            ->assertSessionHasNoErrors();

        $file = $form->submissions()->sole()->getFirstMedia('files');
        $this->assertSame('local', $file->disk);
        Storage::disk('public')->assertDirectoryEmpty('/');

        $link = URL::temporarySignedRoute('submission-files.show', now()->addMinutes(5), ['media' => $file->id]);

        $this->get($link)->assertRedirect();
        $this->actingAs($this->userWithRole('Author'))->get($link)->assertForbidden();
        $this->actingAs($this->userWithRole('Editor'))->get($link)->assertOk()->assertDownload('cv.pdf');
        $this->get(route('submission-files.show', ['media' => $file->id]))->assertForbidden();
    }

    public function test_odd_input_and_a_failing_mail_server_never_break_a_submission(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP down'));
        $form = Form::factory()->create();

        $this->from('/contact')->post(route('forms.submit', $form), [...$this->answers(), '_page' => ['not', 'an', 'id']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $form->submissions()->count());

        $this->from('/contact')->post(route('forms.submit', $form), $this->answers(['budget' => ['nested' => ['x']]]))
            ->assertSessionHasErrors('budget', null, 'form_'.$form->id);
    }

    public function test_a_field_cannot_take_a_key_the_form_uses_itself(): void
    {
        $this->assertContains('website', FormFields::RESERVED_NAMES);
    }

    public function test_choices_are_grouped_and_notes_belong_to_their_field(): void
    {
        $form = Form::factory()->create(['fields' => [
            ['type' => 'radio', 'data' => ['label' => 'Topic', 'name' => 'topic', 'options' => ['Sales', 'Support'], 'required' => true]],
            ['type' => 'text', 'data' => ['label' => 'Name', 'name' => 'name', 'help' => 'As on your ID']],
        ]]);
        Page::factory()->homepage()->create(['blocks' => [['id' => 'f', 'type' => 'form', 'data' => ['form_id' => $form->id]]]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<fieldset[^>]*>\s*<legend[^>]*>\s*Topic#', $html);
        $this->assertMatchesRegularExpression('#aria-describedby="(f-[^"]+-name-note)".*id="\1"#s', $html);
    }
}
