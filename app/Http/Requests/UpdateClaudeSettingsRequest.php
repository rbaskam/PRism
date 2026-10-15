<?php

namespace App\Http\Requests;

use App\Enums\ClaudeAuthMode;
use App\Services\AppSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClaudeSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsCredential = in_array($this->input('auth_mode'), [ClaudeAuthMode::ApiKey->value, ClaudeAuthMode::OauthToken->value], true);
        $settings = $this->container->make(AppSettings::class);
        $keepsExistingCredential = $settings->claudeAuthMode()?->value === $this->input('auth_mode') && $settings->claudeCredential() !== null;

        return [
            'auth_mode' => ['nullable', Rule::enum(ClaudeAuthMode::class)],
            'credential' => [Rule::requiredIf($needsCredential && ! $keepsExistingCredential), 'nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-\[\]_]+$/'],
            'binary_path' => ['nullable', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! is_executable($value)) {
                    $fail('No executable was found at that path.');
                }
            }],
            'max_budget_usd' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credential.required' => 'Paste your API key or token.',
            'model.regex' => 'Use a model alias such as "opus" or "sonnet", or a full model name.',
        ];
    }
}
