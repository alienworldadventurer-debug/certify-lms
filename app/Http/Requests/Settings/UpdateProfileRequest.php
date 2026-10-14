<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\ParameterBag;

class UpdateProfileRequest extends FormRequest
{
    protected function getRedirectUrl(): string
    {
        return URL::route('settings.profile.edit', ['tab' => 'profile']);
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach (['name', 'bio', 'meeting_url'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $trimmedValue = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
                $trimmed = is_string($trimmedValue) ? $trimmedValue : $value;
                $values[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        $this->merge($values);
    }

    protected function failedValidation(Validator $validator)
    {
        $this->removeNonStringValues($this->getInputSource());

        $originalRequest = $this->container->make('request');
        $originalInput = $originalRequest->isJson()
            ? $originalRequest->json()
            : $originalRequest->request;
        $this->removeNonStringValues($originalInput);

        parent::failedValidation($validator);
    }

    private function removeNonStringValues(ParameterBag $input): void
    {
        $values = $input->all();

        foreach (['name', 'bio', 'meeting_url'] as $field) {
            if (array_key_exists($field, $values) && ! is_string($values[$field])) {
                unset($values[$field]);
            }
        }

        $input->replace($values);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! $user->can('updateProfile', $user)) {
            return false;
        }

        return ! $this->exists('meeting_url')
            || $user->can('updateMeetingUrl', $user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->user()?->role === UserRole::Coach) {
            $rules['meeting_url'] = ['nullable', 'string', 'url:http,https', 'max:500'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '氏名を入力してください。',
            'name.string' => '氏名は文字列で入力してください。',
            'name.max' => '氏名は50文字以内で入力してください。',
            'bio.string' => '自己紹介は文字列で入力してください。',
            'bio.max' => '自己紹介は1000文字以内で入力してください。',
            'meeting_url.string' => '固定面談URLは文字列で入力してください。',
            'meeting_url.url' => '固定面談URLを正しい形式で入力してください。',
            'meeting_url.max' => '固定面談URLは500文字以内で入力してください。',
        ];
    }
}
