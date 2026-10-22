<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\Form;
use Aurora\Models\FormSubmission;
use Aurora\Models\Page;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class ConditionalFormFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function form(): Form
    {
        return Form::factory()->create(['fields' => [
            ['type' => 'radio', 'data' => ['label' => 'Reach me by', 'name' => 'reach', 'options' => ['Email', 'Phone'], 'required' => true]],
            ['type' => 'text', 'data' => ['label' => 'Phone number', 'name' => 'phone', 'required' => true, 'show_if_field' => 'reach', 'show_if_value' => 'Phone']],
        ]]);
    }

    protected function send(Form $form, array $answers)
    {
        return $this->from('/contact')->post(route('forms.submit', $form), [
            ...$answers,
            '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ]);
    }

    public function test_a_field_shown_for_another_answer_isn_t_asked_for_or_kept(): void
    {
        $form = $this->form();

        $this->send($form, ['reach' => 'Email', 'phone' => 'sneaked in'])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('phone', FormSubmission::query()->sole()->data);
    }

    public function test_it_is_required_when_its_answer_is_given(): void
    {
        $form = $this->form();

        $this->send($form, ['reach' => 'Phone'])->assertSessionHasErrors(['phone'], errorBag: 'form_'.$form->id);
        $this->send($form, ['reach' => 'Phone', 'phone' => '+47 900 00 000'])->assertSessionHasNoErrors();

        $this->assertSame('+47 900 00 000', FormSubmission::query()->sole()->data['phone']['value']);
    }

    public function test_the_website_hides_it_until_its_answer_is_chosen(): void
    {
        $form = $this->form();
        Page::factory()->homepage()->create(['blocks' => BlockTreeBuilder::flatten([['type' => 'form', 'data' => ['form_id' => $form->id]]])]);

        $this->get('/')->assertOk()->assertSee('x-show="shows(\'reach\', \'Phone\')"', false);
    }
}
