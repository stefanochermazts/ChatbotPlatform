<?php

namespace Database\Factories;

use Database\Seeders\DefaultSynonymsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);
        
        return [
            'name' => $this->faker->company(),
            'slug' => $slug,
            'domain' => $slug . '.example.com',
            'plan' => $this->faker->randomElement(['basic', 'pro', 'enterprise']),
            'metadata' => [
                'created_by' => 'factory',
                'initial_setup' => true,
            ],
            'languages' => ['it', 'en'],
            'default_language' => 'it',
            'custom_system_prompt' => null,
            'custom_context_template' => null,
            'intents_enabled' => [
                'thanks' => true,
                'schedule' => true,
                'address' => true,
                'email' => true,
                'phone' => true,
            ],
            'extra_intent_keywords' => [],
            'kb_scope_mode' => 'relaxed',
            'intent_min_score' => null,
            // I nuovi tenant erediteranno automaticamente i sinonimi di default
            'custom_synonyms' => DefaultSynonymsSeeder::getDefaultSynonyms(),
        ];
    }
}