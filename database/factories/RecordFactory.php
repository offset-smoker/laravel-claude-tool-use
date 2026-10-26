<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Department;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Record>
 */
final class RecordFactory extends Factory
{
    protected $model = Record::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => sprintf('REC-%04d', fake()->unique()->numberBetween(5000, 9999)),
            'title' => 'Synthetic record '.fake()->words(3, true),
            'department' => fake()->randomElement(Department::cases()),
            'filed_on' => fake()->dateTimeBetween('2025-01-01', '2026-09-30'),
            'body' => fake()->paragraph(),
        ];
    }
}
