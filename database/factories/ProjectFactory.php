<?php

namespace Database\Factories;

use App\Domain\Domain;
use App\Models\CreativeBrief;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\QaFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->catchPhrase().' Shoot',
            'description' => fake()->optional()->sentence(),
            'status' => 'active',
            'owner_id' => \App\Models\User::factory(),
        ];
    }

    public function withBrief(): static
    {
        return $this->afterCreating(function (Project $project) {
            CreativeBrief::create([
                'project_id' => $project->id,
                'client' => fake()->company(),
                'shoot_date' => fake()->dateTimeBetween('-30 days', 'now'),
                'location' => fake()->city().', '.fake()->country(),
                'creative_direction' => 'Natural documentary style. Warm ambient tones, '
                    .'shallow depth of field for portraits, honest skin tones.',
                'tonality_notes' => 'Soft highlights, restrained saturation, lift shadows '
                    .'slightly to keep detail in dark fabric.',
                'deliverables' => "20 final selects\r\nWeb + social resized\r\nOne hero image per setup",
                'status' => 'active',
            ]);
        });
    }

    public function withPhotos(int $count = 12): static
    {
        return $this->afterCreating(function (Project $project) use ($count) {
            \App\Models\Photo::factory()->count($count)->create([
                'project_id' => $project->id,
            ]);
        });
    }
}
