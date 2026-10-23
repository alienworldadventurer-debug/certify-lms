<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoogleCredentialStatus;
use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCredential>
 */
class GoogleCredentialFactory extends Factory
{
    protected $model = GoogleCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach(),
            'access_token' => 'dummy-access-token-'.fake()->sha1(),
            'refresh_token' => 'dummy-refresh-token-'.fake()->sha1(),
            'expires_at' => now()->addHour(),
            'calendar_id' => fake()->unique()->safeEmail(),
            'status' => GoogleCredentialStatus::Active->value,
            'connected_at' => now(),
        ];
    }

    public function forCoach(User $coach): static
    {
        return $this->state(fn () => [
            'user_id' => $coach->id,
        ]);
    }
}
