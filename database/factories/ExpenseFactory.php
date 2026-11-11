<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_date' => fake()->date(),
            'amount_millimes' => fake()->numberBetween(1, 100000000),
            'category_id' => Category::factory(),
            'description' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
