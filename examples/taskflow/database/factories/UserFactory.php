<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Ions\Database\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected string $model = User::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            // bcrypt('password') — a stable known hash so seeded demo users can
            // sign in with "password".
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'email_verified_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ];
    }

    /** A user whose email has not been verified. */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    /** A user with two-factor authentication configured. */
    public function withTwoFactor(): static
    {
        return $this->state([
            'two_factor_secret' => $this->faker->regexify('[A-Z2-7]{16}'),
            'two_factor_recovery_codes' => json_encode(
                $this->faker->words(8),
                JSON_THROW_ON_ERROR
            ),
        ]);
    }
}
