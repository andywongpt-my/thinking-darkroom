<?php

namespace Database\Factories;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => \App\Models\User::factory(),
            'type' => Domain::TYPE_CULL,
            'status' => Domain::STATE_PENDING_REVIEW,
            'summary' => fake()->sentence(),
            'payload' => ['created_via' => 'webmcp'],
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => ['status' => Domain::STATE_PENDING_REVIEW]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => Domain::STATE_APPROVED,
            'reviewed_at' => now(),
        ]);
    }

    public function executed(): static
    {
        return $this->state(fn () => [
            'status' => Domain::STATE_EXECUTED,
            'reviewed_at' => now(),
            'executed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => Domain::STATE_REJECTED,
            'reviewed_at' => now(),
        ]);
    }
}
