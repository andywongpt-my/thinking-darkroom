<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\PhotoDerivative;
use App\Models\Project;
use App\Models\User;
use App\Services\Media\MediaStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProjectController extends Controller
{
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $project = DB::transaction(function () use ($user, $validated): Project {
            $project = Project::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => 'active',
                'owner_id' => $user->id,
            ]);

            $project->members()->attach($user->id, [
                'role' => Domain::ROLE_OWNER,
            ]);

            return $project;
        });

        return redirect()->route('workspace.show', $project);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()->route('dashboard')->with('flash', [
            'success' => 'Project updated.',
        ]);
    }

    public function destroy(Project $project, MediaStore $mediaStore): RedirectResponse
    {
        $this->authorize('destroy', $project);

        // Collect storage paths inside the transaction, delete the bytes AFTER
        // it commits: byte stores cannot roll back, so deleting inside the
        // transaction would orphan rows if the record deletes ever failed
        // (AGY 2026-09-02 audit Finding 1).
        $paths = DB::transaction(function () use ($project): array {
            $paths = [];

            foreach ($project->photos()->get() as $photo) {
                foreach (PhotoDerivative::query()->where('photo_id', $photo->id)->get() as $derivative) {
                    if ($this->deletablePath($derivative->storage_path)) {
                        $paths[] = $derivative->storage_path;
                    }
                }

                if ($this->deletablePath($photo->path)) {
                    $paths[] = $photo->path;
                }
            }

            $project->delete();

            return $paths;
        });

        foreach ($paths as $path) {
            $this->tryDeleteMedia($mediaStore, $path);
        }

        return redirect()->route('dashboard')->with('flash', [
            'success' => 'Project deleted.',
        ]);
    }

    private function deletablePath(?string $path): bool
    {
        return $path !== null && $path !== '';
    }

    private function tryDeleteMedia(MediaStore $mediaStore, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            $mediaStore->delete($path);
        } catch (Throwable) {
            // A missing/unavailable byte store must not prevent database cleanup.
        }
    }
}
