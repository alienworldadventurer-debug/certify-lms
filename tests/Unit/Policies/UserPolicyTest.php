<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UserPolicy の ability × Role マトリクス検証。
 * 管理者向けユーザー運用 ability 5 種 (viewAny / view / withdraw / extendCourse / grantMeetingQuota) × 3 ロール = 15 ケースを検証する。
 * 「他者のロール / プロフィール変更」動線は LMS で提供しないため、update ability は Policy 側に存在しない。
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_abilities_allow_the_owner_for_every_role(): void
    {
        $policy = new UserPolicy;

        foreach (['student', 'coach', 'admin'] as $role) {
            $user = User::factory()->{$role}()->create();

            foreach ([
                'viewSettings',
                'updateProfile',
                'updateAvatar',
                'deleteAvatar',
                'updatePassword',
            ] as $ability) {
                $this->assertTrue($policy->{$ability}($user, $user), "{$role} should be allowed to {$ability} their settings.");
            }
        }
    }

    public function test_settings_abilities_deny_another_users_resource(): void
    {
        $policy = new UserPolicy;
        $actor = User::factory()->student()->create();
        $target = User::factory()->student()->create();

        foreach ([
            'viewSettings',
            'updateProfile',
            'updateAvatar',
            'deleteAvatar',
            'updatePassword',
            'updateMeetingUrl',
        ] as $ability) {
            $this->assertFalse($policy->{$ability}($actor, $target), "Another user should not be allowed to {$ability}.");
        }
    }

    public function test_only_coaches_can_update_their_own_meeting_url(): void
    {
        $policy = new UserPolicy;

        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->{$role}()->create();

            $this->assertFalse($policy->updateMeetingUrl($user, $user));
        }

        $coach = User::factory()->coach()->create();
        $this->assertTrue($policy->updateMeetingUrl($coach, $coach));
    }

    /**
     * 管理者向けユーザー運用 ability × Role のマトリクス検証。
     * Admin のみ全 ability で true、Coach / Student は全 ability で false が期待値。
     */
    #[DataProvider('adminOnlyAbilityMatrix')]
    public function test_admin_only_abilities_match_role_expectation(
        string $actingRole,
        string $policyMethod,
        bool $expected,
    ): void {
        // Arrange
        $actor = User::factory()->{$actingRole}()->create();
        $target = User::factory()->student()->create();
        $policy = new UserPolicy;

        // Act
        $result = $policy->{$policyMethod}($actor, $target);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            "{$actingRole} が {$policyMethod} で ".($expected ? 'true' : 'false').' を返すべきだが反対の結果が返った',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function adminOnlyAbilityMatrix(): array
    {
        $abilities = ['viewAny', 'view', 'withdraw', 'extendCourse', 'grantMeetingQuota'];
        $roles = [
            'admin' => true,
            'coach' => false,
            'student' => false,
        ];

        $cases = [];
        foreach ($roles as $role => $expected) {
            foreach ($abilities as $ability) {
                $caseKey = $expected
                    ? "{$role} は {$ability} を実行できる"
                    : "{$role} は {$ability} を実行できない";
                $cases[$caseKey] = [$role, $ability, $expected];
            }
        }

        return $cases;
    }
}
