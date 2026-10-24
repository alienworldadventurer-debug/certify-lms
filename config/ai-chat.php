<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI 相談機能の有効化
    |--------------------------------------------------------------------------
    |
    | OFF のときは全 ai-chat ルートが 404 になる。
    |
    */
    'enabled' => (bool) env('AI_CHAT_ENABLED', false),

    /*
    | 受講生 1 人あたりの 1 日の送信上限（新しい相談の最初の質問も含む）。
    */
    'daily_limit' => (int) env('AI_CHAT_DAILY_LIMIT', 50),

    /*
    | AI への入力に含める直近のメッセージ件数。
    */
    'history_limit' => (int) env('AI_CHAT_HISTORY_LIMIT', 10),

    /*
    | 最初の AI 応答後に、AI に会話タイトルを付け直させるかどうか。
    */
    'auto_title' => (bool) env('AI_CHAT_AUTO_TITLE_ENABLED', true),

    'system_prompt' => env(
        'AI_CHAT_SYSTEM_PROMPT',
        'あなたは資格学習を支援するアシスタントです。受講生の質問に、わかりやすい日本語で簡潔に答えてください。'
    ),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => 60,
    ],

];
