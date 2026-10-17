<?php

namespace Database\Factories;

use App\Models\Income;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Income>
 */
class IncomeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount'      => $this->faker->randomFloat(2, 10, 999.99),
            'source'      => $this->faker->jobTitle(),
            'income_date' => $this->faker->date(),
        ];
    }
}

