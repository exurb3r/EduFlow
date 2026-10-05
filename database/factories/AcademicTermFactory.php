<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicTerm> */
class AcademicTermFactory extends Factory
{
    protected $model = AcademicTerm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Academic Term ####-????'),
            'starts_on' => today()->startOfYear(),
            'ends_on' => today()->endOfYear(),
        ];
    }
}
