<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteProjectAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        return $user instanceof User
            && ! $user->isAgent()
            && $project instanceof Project
            && $user->can('update', $project);
    }

    protected function getRedirectUrl(): string
    {
        return route('dashboard');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::exists(User::class, 'email')->where('is_agent', true),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.exists' => 'Enter the email of an existing agent account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }
}
