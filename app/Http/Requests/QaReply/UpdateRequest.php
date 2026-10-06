<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');
        $reply = $this->route('reply');

        if (! $thread instanceof QaThread || ! $reply instanceof QaReply || $this->user() === null) {
            return false;
        }

        abort_if($reply->qa_thread_id !== $thread->id, 404);

        Gate::forUser($this->user())->authorize('update', $reply);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => '回答は必須です。',
            'body.max' => '回答は5,000文字以内で入力してください。',
        ];
    }
}
