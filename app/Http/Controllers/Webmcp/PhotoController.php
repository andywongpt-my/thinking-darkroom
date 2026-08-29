<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Project;
use App\Services\Media\MediaStore;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    public function __construct(private readonly ToolCallAuditService $audit) {}

    /** list_project_photos */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $photos = $project->photos()
            ->orderBy('id')
            ->get()
            ->map(fn (Photo $p) => $this->photoSummary($p))
            ->values();

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'list_project_photos',
            Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['count' => $photos->count()],
        );

        return response()->json(['project_id' => $project->id, 'count' => $photos->count(), 'photos' => $photos]);
    }

    /** inspect_photo */
    public function show(Request $request, Project $project, Photo $photo): JsonResponse
    {
        $this->authorize('view', $project);

        $abort = $this->authorizePhoto($request, $project, $photo);
        if ($abort) {
            return $abort;
        }

        $data = [
            'photo' => $this->photoDetail($photo),
        ];

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'inspect_photo',
            Domain::AUTHORITY_READ,
            ['project_id' => $project->id, 'photo_id' => $photo->id],
            ['filename' => $photo->filename],
        );

        return response()->json($data);
    }

    /* ---------------------------------- helpers ---------------------------------- */

    private function authorizePhoto(Request $request, Project $project, Photo $photo): ?JsonResponse
    {
        if ($photo->project_id !== $project->id) {
            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'inspect_photo',
                Domain::AUTHORITY_READ,
                ['project_id' => $project->id, 'photo_id' => $photo->id],
                ['error' => 'photo does not belong to project'],
                Domain::RESULT_DENIED,
            );

            return response()->json(['error' => 'Photo does not belong to project.'], 404);
        }

        return null;
    }

    public static function photoSummary(Photo $p): array
    {
        return [
            'id' => $p->id,
            'filename' => $p->filename,
            'url' => MediaStore::publicUrl($p->path),
            'mime' => $p->mime,
            'width' => $p->width,
            'height' => $p->height,
            'size_bytes' => $p->size_bytes,
            'selection_state' => $p->selection_state,
            'retouch_state' => $p->retouch_state,
        ];
    }

    public static function photoDetail(Photo $p): array
    {
        return self::photoSummary($p) + [
            'original_name' => $p->original_name,
            'camera_make' => $p->camera_make,
            'camera_model' => $p->camera_model,
            'lens' => $p->lens,
            'iso' => $p->iso,
            'aperture' => $p->aperture,
            'shutter_speed' => $p->shutter_speed,
            'focal_length' => $p->focal_length,
            'captured_at' => $p->captured_at?->toIso8601String(),
        ];
    }
}
