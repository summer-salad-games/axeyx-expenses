<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(Str::password(32)),
            'role' => Role::Viewer,
            'is_active' => true,
            'auth_version' => 1,
        ];
    }

    public function administrator(): static
    {
        return $this->state(fn (): array => ['role' => Role::Administrator]);
    }

    public function editor(): static
    {
        return $this->state(fn (): array => ['role' => Role::Editor]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
