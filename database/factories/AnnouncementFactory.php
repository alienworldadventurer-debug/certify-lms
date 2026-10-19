<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'target_type' => AnnouncementTargetType::AllStudents,
            'target_certification_id' => null,
            'target_user_id' => null,
            'dispatched_count' => 0,
            'dispatched_at' => now(),
            'created_by' => User::factory()->admin(),
        ];
    }

    public function allStudents(): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::AllStudents,
            'target_certification_id' => null,
            'target_user_id' => null,
        ]);
    }

    public function forCertification(?Certification $certification = null): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::Certification,
            'target_certification_id' => $certification?->getKey() ?? Certification::factory(),
            'target_user_id' => null,
        ]);
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::User,
            'target_certification_id' => null,
            'target_user_id' => $user?->getKey() ?? User::factory()->student(),
        ]);
    }
}
