<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $pin;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile' => '8801'.fake()->unique()->numerify('########'),
            'email' => null,
            'pin_hash' => static::$pin ??= '123456',
            'tenant_id' => null,
            'is_platform_admin' => false,
            'remember_token' => Str::random(10),
        ];
    }
}
