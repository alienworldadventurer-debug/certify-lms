<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談メモ作成・更新 Action の状態制約と upsert 挙動を検証する。
 */
class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_memo_for_reserved_meeting(): void
    {
        // Arrange
        $meeting = Meeting::factory()->reserved()->create();

        // Act
        app(UpsertMemoAction::class)($meeting, '初回のメモ');

        // Assert
        $this->assertDatabaseHas('meeting_memos', ['meeting_id' => $meeting->id, 'body' => '初回のメモ']);
    }

    public function test_updates_existing_memo_without_duplicating(): void
    {
        // Arrange
        $meeting = Meeting::factory()->completed()->create();
        MeetingMemo::create(['meeting_id' => $meeting->id, 'body' => '古いメモ']);

        // Act
        app(UpsertMemoAction::class)($meeting, '更新後のメモ');

        // Assert
        $this->assertSame(1, MeetingMemo::where('meeting_id', $meeting->id)->count());
        $this->assertSame('更新後のメモ', MeetingMemo::where('meeting_id', $meeting->id)->value('body'));
    }

    public function test_canceled_meeting_rejects_memo(): void
    {
        // Arrange
        $meeting = Meeting::factory()->canceled()->create();

        // Act & Assert
        $this->expectException(MeetingStatusTransitionException::class);
        app(UpsertMemoAction::class)($meeting, 'メモ');
    }
}
