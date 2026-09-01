<?php

namespace Database\Seeders;

use App\Domain\Domain;
use App\Models\CreativeConcept;
use App\Models\Photo;
use App\Models\PhotoObservationRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\CreativeRoomService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 3 certification seeder — context-aware culling demo project.
 *
 * Deterministic by construction:
 *  - project name is a fixed unique key → upsert, never duplicates;
 *  - photos are matched by original_name → re-run refreshes, never re-creates;
 *  - creative concept is matched by title → the adopted brief is stable;
 *  - IDs are NOT hard-coded anywhere — tests resolve them programmatically.
 *
 * Isolation: touches ONLY the "Sprint 3 Certification — Culling Demo"
 * project. Sprint 1 demo (Coastal Studio) and Sprint 2 certification
 * fixtures are never read or modified.
 *
 * The dataset JPEGs come from database/demo/culling-dataset/ — original
 * synthetic composites generated for Thinking Darkroom (see that folder's
 * README.md for provenance; no third-party photography).
 */
class Sprint3CullingSeeder extends Seeder
{
    public const PROJECT_NAME = 'Sprint 3 Certification — Culling Demo';

    public const CONCEPT_TITLE = 'Documentary Intimacy (emotion over perfection)';

    public function run(): void
    {
        $photographer = User::query()->updateOrCreate(
            ['email' => 'photographer@webmcp.test'],
            ['name' => 'Maya Tanaka (Photographer)', 'is_agent' => false, 'password' => 'password'],
        );

        $agent = User::query()->updateOrCreate(
            ['email' => 'agent@webmcp.test'],
            ['name' => 'WebMCP Agent', 'is_agent' => true, 'password' => 'password'],
        );

        $project = Project::query()->updateOrCreate(
            ['name' => self::PROJECT_NAME],
            [
                'description' => 'Sprint 3 context-aware culling demo. 12 original synthetic '
                    .'JPEG frames (generated for Thinking Darkroom — see database/demo/'
                    .'culling-dataset/README.md). Technical observations come from real '
                    .'pixel analysis; creative labels are demo sidecar annotations.',
                'status' => 'active',
                'owner_id' => $photographer->id,
            ],
        );

        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        $this->seedDatasetPhotos($project);

        // The bundled certification assets are immutable, but their derived
        // pixel/sidecar observations are not. Re-seeding this demo must drop
        // only its stale evidence so the next ANALYZE run reads the current
        // committed bundle instead of preserving a prior degraded result.
        self::resetObservations($project);

        $this->seedAdoptedDirection($project, $photographer, $agent);
    }

    /**
     * Copy each dataset JPEG (+ sidecar) into the public disk and upsert the
     * Photo row. If a stale observation exists it is removed so a later
     * analyze run re-derives it from the current pixels.
     */
    private function seedDatasetPhotos(Project $project): void
    {
        $datasetDir = database_path('demo/culling-dataset');
        $files = glob($datasetDir.'/*.jpg');
        sort($files);

        foreach ($files as $file) {
            $original = basename($file);
            $bytes = file_get_contents($file);
            if ($bytes === false) {
                continue;
            }
            $sidecarPath = $file.'.obs.json';
            $sidecar = is_file($sidecarPath) ? (string) file_get_contents($sidecarPath) : null;

            $path = "project-{$project->id}/".$original;
            Storage::disk('public')->put($path, $bytes);
            if ($sidecar !== null) {
                Storage::disk('public')->put($path.'.obs.json', $sidecar);
            }

            $dims = @getimagesizefromstring($bytes) ?: [0, 0];

            Photo::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'original_name' => $original,
                ],
                [
                    'filename' => $original,
                    'path' => $path,
                    'mime' => 'image/jpeg',
                    'size_bytes' => strlen($bytes),
                    'width' => $dims[0],
                    'height' => $dims[1],
                    'selection_state' => Domain::SELECTION_UNREVIEWED,
                    'retouch_state' => Domain::RETOUCH_NONE,
                    'camera_model' => 'Thinking Darkroom Synthetic',
                    'iso' => 200,
                    'aperture' => 2.8,
                    'focal_length' => 50,
                ],
            );
        }
    }

    /**
     * Give the project an ADOPTED creative concept so
     * CreativeRoomService::structuredIntentFor() yields the structured brief:
     * emotion > technical perfection, documentary intimacy, avoid overly posed.
     *
     * The adoption is performed through the real CreativeRoomService path
     * (as the photographer would) so the derived brief + decision trail are
     * exactly what production code produces.
     */
    private function seedAdoptedDirection(Project $project, User $photographer, User $agent): void
    {
        $content = [
            'mood' => ['intimate', 'quiet', 'documentary'],
            'story' => 'Honest documentary intimacy — the feeling of the moment matters '
                .'more than technical perfection.',
            'selection_priorities' => ['emotion' => 'primary', 'technical' => 'secondary'],
            'avoid' => ['overly posed expressions', 'stiff formal staging'],
            'composition' => 'Loose environmental framing, subject in context.',
            'lighting' => 'Available light only.',
        ];

        $concept = CreativeConcept::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'title' => self::CONCEPT_TITLE,
            ],
            [
                'summary' => 'Emotion-first documentary direction for the culling demo.',
                'content' => $content,
                'status' => Domain::CONCEPT_STATUS_PROPOSED,
                'created_by' => $agent->id, // agent proposed; photographer adopts
            ],
        );

        if ($concept->status !== Domain::CONCEPT_STATUS_ADOPTED) {
            app(CreativeRoomService::class)
                ->adoptConcept($project, $photographer, $concept, 'Seeded demo direction');
        }
    }

    /**
     * Drop stale analysis rows for this project so a certification run
     * re-analyzes from pixels (fresh evidence, same deterministic values).
     */
    public static function resetObservations(Project $project): int
    {
        return PhotoObservationRecord::where('project_id', $project->id)->delete();
    }
}
