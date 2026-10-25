<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_owner_can_perform_all_conversation_operations(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $policy = new AiChatConversationPolicy;

        $this->assertTrue($policy->create($student));
        $this->assertTrue($policy->view($student, $conversation));
        $this->assertTrue($policy->update($student, $conversation));
        $this->assertTrue($policy->delete($student, $conversation));
        $this->assertTrue($policy->sendMessage($student, $conversation));
    }

    public function test_other_student_cannot_operate_on_someone_elses_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($other, $conversation));
        $this->assertFalse($policy->update($other, $conversation));
        $this->assertFalse($policy->delete($other, $conversation));
        $this->assertFalse($policy->sendMessage($other, $conversation));
    }

    public function test_student_who_is_no_longer_in_progress_cannot_operate_even_on_own_conversation(): void
    {
        $policy = new AiChatConversationPolicy;

        foreach ([UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $student = User::factory()->student()->create(['status' => $status]);
            $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

            $this->assertFalse($policy->create($student), $status->value);
            $this->assertFalse($policy->view($student, $conversation), $status->value);
            $this->assertFalse($policy->sendMessage($student, $conversation), $status->value);
        }
    }

    public function test_non_student_roles_cannot_operate_on_conversations(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $policy = new AiChatConversationPolicy;

        foreach ([User::factory()->admin()->create(), User::factory()->coach()->inProgress()->create()] as $user) {
            $this->assertFalse($policy->create($user));
            $this->assertFalse($policy->view($user, $conversation));
            $this->assertFalse($policy->sendMessage($user, $conversation));
        }
    }
}
