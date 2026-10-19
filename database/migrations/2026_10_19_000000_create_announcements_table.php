<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->text('body');
            $table->string('target_type', 20);
            $table->foreignUlid('target_certification_id')->nullable()->constrained('certifications')->nullOnDelete();
            $table->foreignUlid('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->timestamp('dispatched_at');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['dispatched_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
