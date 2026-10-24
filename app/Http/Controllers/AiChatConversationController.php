<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\AiChatLlmFailedException;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\UseCases\AiChatConversation\DestroyAction;
use App\UseCases\AiChatConversation\StoreAction;
use App\UseCases\AiChatConversation\StoreMessageAction;
use App\UseCases\AiChatConversation\UpdateAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AiChatConversationController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $latest = $request->user()->aiChatConversations()
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest === null) {
            return view('ai-chat.empty-state');
        }

        return redirect()->route('ai-chat.conversations.show', $latest);
    }

    public function store(StoreConversationRequest $request, StoreAction $action): JsonResponse|RedirectResponse
    {
        $data = $request->validated();

        $result = $action($request->user(), $data['message'] ?? null, $data['section_id'] ?? null);
        $conversation = $result['conversation'];

        if ($request->expectsJson()) {
            if ($result['error'] !== null) {
                throw $result['error'];
            }

            return response()->json(
                ['conversation' => $this->conversationPayload($conversation)],
                $result['created'] ? 201 : 200,
            );
        }

        $redirect = redirect()->route('ai-chat.conversations.show', $conversation);

        if ($result['error'] !== null) {
            return $redirect->with('error', $result['error']->getMessage());
        }

        return $redirect;
    }

    public function show(Request $request, AiChatConversation $conversation): JsonResponse|View
    {
        $this->authorize('view', $conversation);

        $conversation->load(['section', 'enrollment.certification', 'messages']);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $this->conversationPayload($conversation),
                'messages' => $conversation->messages->map(fn (AiChatMessage $m) => $this->messagePayload($m))->values(),
            ]);
        }

        return view('ai-chat.show', ['conversation' => $conversation]);
    }

    public function update(
        UpdateConversationRequest $request,
        AiChatConversation $conversation,
        UpdateAction $action,
    ): JsonResponse|RedirectResponse {
        $updated = $action($conversation, $request->validated('title'));

        if ($request->expectsJson()) {
            return response()->json(['conversation' => $this->conversationPayload($updated)]);
        }

        return redirect()
            ->route('ai-chat.conversations.show', $updated)
            ->with('success', '会話タイトルを更新しました。');
    }

    public function destroy(Request $request, AiChatConversation $conversation, DestroyAction $action): Response|RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $action($conversation);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('ai-chat.index')
            ->with('success', '会話を削除しました。');
    }

    public function storeMessage(
        StoreMessageRequest $request,
        AiChatConversation $conversation,
        StoreMessageAction $action,
    ): JsonResponse|RedirectResponse {
        try {
            $result = $action($conversation, $request->validated('content'));
        } catch (AiChatLlmFailedException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return redirect()
                ->route('ai-chat.conversations.show', $conversation)
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'user_message' => $this->messagePayload($result['user_message']),
                'assistant_message' => $this->messagePayload($result['assistant_message']),
                'conversation' => $this->conversationPayload($conversation->refresh()),
            ]);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(AiChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'section_id' => $conversation->section_id,
            'enrollment_id' => $conversation->enrollment_id,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * 受講生に見せる項目のみ返す。モデル名・入力トークン数は含めない。
     *
     * @return array<string, mixed>
     */
    private function messagePayload(AiChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'status' => $message->status->value,
            'error_detail' => $message->error_detail,
            'output_tokens' => $message->output_tokens,
            'response_time_ms' => $message->response_time_ms,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
