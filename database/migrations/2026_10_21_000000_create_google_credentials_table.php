<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コーチの Google アカウント連携情報テーブル(1 ユーザー : 1 件)。
 *
 * トークンは暗号化せず保存する(スコープ外)。本番運用では暗号化を推奨する。
 * status は `active`(連携中) / `error`(連携エラー) を保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('expires_at');
            $table->string('calendar_id');
            $table->string('status', 20)->default('active');
            $table->dateTime('connected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
