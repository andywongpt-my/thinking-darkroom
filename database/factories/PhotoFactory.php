<?php

namespace Database\Factories;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    public function definition(): array
    {
        $states = [Domain::SELECTION_UNREVIEWED, Domain::SELECTION_SELECTED, Domain::SELECTION_CULLED];

        return [
            'project_id' => Project::factory(),
            'filename' => fn () => strtolower(fake()->unique()->bothify('IMG_####')).'.jpg',
            'original_name' => fn (array $attrs) => 'photo-'.$attrs['filename'],
            'path' => fn () => 'project-0/placeholder.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(200_000, 12_000_000),
            'width' => fake()->numberBetween(2000, 6000),
            'height' => fake()->numberBetween(1500, 4000),
            'selection_state' => fake()->randomElement($states),
            'retouch_state' => Domain::RETOUCH_NONE,
            'camera_model' => fake()->randomElement(['Canon EOS R5', 'Sony A7 IV', 'Nikon Z6 II', null]),
            'iso' => fake()->randomElement([100, 200, 400, 800, null]),
            'focal_length' => fake()->randomElement(['24', '35', '50', '85', null]),
        ];
    }
}
