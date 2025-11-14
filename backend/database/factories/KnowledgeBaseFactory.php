<?php

namespace Database\Factories;

use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'is_default' => false,
        ];
    }
}
