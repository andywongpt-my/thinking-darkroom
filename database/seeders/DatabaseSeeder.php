<?php

namespace Database\Seeders;

use App\Domain\Domain;
use App\Models\CreativeBrief;
use App\Models\Photo;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Actors
        // ---------------------------------------------------------------
        $photographer = User::query()->updateOrCreate(
            ['email' => 'photographer@webmcp.test'],
            [
                'name' => 'Maya Tanaka (Photographer)',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_agent' => false,
            ],
        );

        $agent = User::query()->updateOrCreate(
            ['email' => 'agent@webmcp.test'],
            [
                'name' => 'WebMCP Agent',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_agent' => true,
            ],
        );

        // ---------------------------------------------------------------
        // One demo project
        // ---------------------------------------------------------------
        $project = Project::query()->updateOrCreate(
            ['name' => 'Coastal Studio — Editorial Portraits'],
            [
                'description' => 'Demo project for the WebMCP Challenge. '
                    .'A 12-photo editorial portrait set for a coastal lifestyle magazine.',
                'status' => 'active',
                'owner_id' => $photographer->id,
            ],
        );

        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        CreativeBrief::query()->updateOrCreate(
            ['project_id' => $project->id],
            [
                'client' => 'Coastal Magazine (demo)',
                'shoot_date' => now()->subDays(6)->toDateString(),
                'location' => 'Tanjung Aru beach, Kota Kinabalu',
                'creative_direction' => 'Natural documentary style. Warm ambient tones, '
                    .'shallow depth of field for portraits, honest skin tones, '
                    .'strong horizon lines and negative space.',
                'tonality_notes' => 'Soft highlights, restrained saturation, lift shadows '
                    .'slightly to keep detail in dark fabric and skin.',
                'deliverables' => "16-20 final selects\r\nWeb + social resized set\r\nOne hero per setup",
                'status' => 'active',
            ],
        );

        // ---------------------------------------------------------------
        // Placeholder photo records (no copyrighted imagery)
        // ---------------------------------------------------------------
        $this->seedPlaceholderPhotos($project);

        // ---------------------------------------------------------------
        // Small decision-history for the Agent Activity panel demo.
        // Idempotent (Sol Max P2): firstOrCreate keyed on project + summary
        // so re-seeding never duplicates demo proposals/decisions.
        // ---------------------------------------------------------------
        $photos = $project->photos()->get();
        if ($photos->count() >= 6) {
            $cull = Proposal::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'type' => Domain::TYPE_CULL,
                    'summary' => 'Cull 3 technically-weak frames (motion blur / soft focus) before selects.',
                ],
                [
                    'created_by' => $agent->id,
                    'status' => Domain::STATE_REJECTED,
                    'payload' => ['created_via' => 'webmcp', 'tool' => 'propose_cull'],
                    'reviewed_by' => $photographer->id,
                    'reviewed_at' => now()->subDays(2),
                ],
            );
            if ($cull->wasRecentlyCreated) {
                $this->attachCullItems($cull, $photos, 3);
            }

            PhotographerDecision::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'proposal_id' => $cull->id,
                    'decision' => 'reject',
                ],
                [
                    'photographer_id' => $photographer->id,
                    'note' => 'Keep those — motion blur on frame 7 is actually intentional.',
                    'created_at' => now()->subDays(2),
                ],
            );
        }
    }

    private function seedPlaceholderPhotos(Project $project): void
    {
        // Build an SVG placeholder per photo so the grid renders real thumbnails
        // without any copyrighted imagery.
        $names = [
            'portrait-warm-light.jpg',
            'harbor-golden-hour.jpg',
            'model-linen.jpg',
            'beach-line.jpg',
            'tide-pools.jpg',
            'editorial-relaxed.jpg',
            'dusk-silhouette.jpg',
            'candid-laugh.jpg',
            'boat-raft.jpg',
            'palm-shadow.jpg',
            'studio-softbox.jpg',
            'seawall-walk.jpg',
        ];

        foreach ($names as $i => $name) {
            $existing = $project->photos()->where('original_name', $name)->first();
            if ($existing) {
                continue;
            }

            $hue = (($i * 37) % 360);
            $state = [Domain::SELECTION_SELECTED, Domain::SELECTION_UNREVIEWED, Domain::SELECTION_UNREVIEWED, Domain::SELECTION_UNREVIEWED][$i % 4];
            $retouch = Domain::RETOUCH_NONE;

            $svg = $this->svgPlaceholder("demo-{$i}", $hue);
            $filename = 'demo-'.($i + 1).'.svg';
            Storage::disk('public')->put("project-{$project->id}/{$filename}", $svg);

            Photo::query()->create([
                'project_id' => $project->id,
                'filename' => $filename,
                'original_name' => $name,
                'path' => "project-{$project->id}/{$filename}",
                'mime' => 'image/svg+xml',
                'size_bytes' => strlen($svg),
                'width' => 1200,
                'height' => 800,
                'selection_state' => $state,
                'retouch_state' => $retouch,
                'camera_model' => ['Canon EOS R5', 'Sony A7 IV', 'Nikon Z6 II'][$i % 3],
                'iso' => [100, 200, 400, 800][$i % 4],
                'aperture' => [2.8, 4.0, 1.8, 5.6][$i % 4],
                'focal_length' => [35, 50, 85, 24][$i % 4],
            ]);
        }
    }

    public function attachCullItems(Proposal $proposal, $photos, int $count): void
    {
        foreach ($photos->take($count) as $photo) {
            ProposalItem::query()->create([
                'proposal_id' => $proposal->id,
                'photo_id' => $photo->id,
                'kind' => 'selection',
                'action' => 'cull',
                'rationale' => 'Technical reject — motion blur / soft focus detected.',
                'params' => ['reason' => 'blur'],
            ]);
        }
    }

    private function svgPlaceholder(string $label, int $hue): string
    {
        $c1 = "hsl({$hue}, 55%, 42%)";
        $c2 = 'hsl('.((($hue + 40) % 360)).', 60%, 26%)';

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="{$c1}"/>
              <stop offset="100%" stop-color="{$c2}"/>
            </linearGradient>
          </defs>
          <rect width="1200" height="800" fill="url(#g)"/>
          <circle cx="880" cy="250" r="150" fill="rgba(255,255,255,0.12)"/>
          <circle cx="300" cy="640" r="210" fill="rgba(0,0,0,0.10)"/>
          <text x="600" y="420" font-family="sans-serif" font-size="52" fill="rgba(255,255,255,0.85)" text-anchor="middle">DEMO {$label}</text>
          <text x="600" y="480" font-family="sans-serif" font-size="24" fill="rgba(255,255,255,0.55)" text-anchor="middle">WebMCP Challenge placeholder</text>
        </svg>
        SVG;
    }
}
