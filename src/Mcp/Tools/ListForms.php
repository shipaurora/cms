<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Form;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_forms')]
#[Description('Forms that can be placed on a page with the "form" block (use the id as form_id), with their fields and how many new submissions they have.')]
#[IsReadOnly]
class ListForms extends CmsTool
{
    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('view-any Form');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response
    {
        return Response::json([
            'forms' => Form::query()->withCount('newSubmissions')->orderBy('name')->get()->map(fn (Form $form): array => [
                'id' => $form->id,
                'name' => $form->name,
                'accepting_submissions' => $form->is_active,
                'fields' => collect($form->definitions())->map(fn (array $field): string => $field['label'].' ('.$field['type'].($field['required'] ? ', required' : '').')')->all(),
                'new_submissions' => $form->new_submissions_count,
            ]),
        ]);
    }
}
