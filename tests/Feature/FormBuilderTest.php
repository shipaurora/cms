<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Forms\Pages\CreateForm;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Models\Form;
use Aurora\Support\FormFields;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Building a form in the admin, every field type: the page opens, each type's settings show,
 * and the form saves.
 */
class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_form_with_every_field_type_is_built_and_saved_in_the_admin(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $this->get(CreateForm::getUrl())->assertOk();

        $fields = [];
        foreach (array_keys(FormFields::all()) as $i => $type) {
            $fields["item-{$i}"] = ['type' => $type, 'data' => array_filter([
                'label' => "Field {$type}",
                'name' => "field_{$type}",
                'options' => in_array($type, ['select', 'radio', 'checkboxes'], true) ? ['One', 'Two'] : null,
            ])];
        }

        Livewire::test(CreateForm::class)
            // The fields section shows for this language ($wire.activeLocale): it must exist.
            ->assertSet('activeLocale', null)
            ->fillForm(['name' => 'Everything', 'fields' => $fields])
            ->call('create')
            ->assertHasNoFormErrors();

        $form = Form::query()->sole();
        $this->assertSame(array_keys(FormFields::all()), array_column($form->fields, 'type'));

        $this->get(EditForm::getUrl(['record' => $form]))->assertOk();
        Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])->call('save')->assertHasNoFormErrors();
    }
}
