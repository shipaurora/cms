<?php

namespace Aurora\Database\Factories;

use Aurora\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Contact',
            'submit_label' => 'Send',
            'success_message' => 'Thanks! We will be in touch.',
            'notify' => ['hello@example.com'],
            'is_active' => true,
            'fields' => [
                ['type' => 'text', 'data' => ['label' => 'Name', 'name' => 'name', 'required' => true]],
                ['type' => 'email', 'data' => ['label' => 'Email', 'name' => 'email', 'required' => true]],
                ['type' => 'select', 'data' => ['label' => 'Budget', 'name' => 'budget', 'options' => ['Under €10k', '€10–50k', 'Over €50k']]],
                ['type' => 'textarea', 'data' => ['label' => 'Message', 'name' => 'message', 'required' => true]],
                ['type' => 'consent', 'data' => ['label' => 'I agree to the privacy policy', 'name' => 'consent', 'required' => true]],
            ],
        ];
    }
}
