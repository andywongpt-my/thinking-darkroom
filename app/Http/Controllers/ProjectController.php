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
use RuntimeException;
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
        $this->authorize('update', $project);
        $project->update($request->validated());

        return redirect()->route('dashboard')->with('flash', [
            'success' => 'Project updated.',
        ]);
    }

    public function destroy(Project $project, MediaStore $mediaStore): RedirectResponse
    {
        $this->authorize('destroy', $project);

        $paths = $this->mediaPathsForProject($project);

        try {
            $this->deleteMediaOrFail($mediaStore, $paths);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'media' => 'Unable to delete project media. The project was not deleted.',
            ]);
        }

        try {
            $deleted = DB::transaction(fn (): bool => $project->delete() === true);
            if (! $deleted) {
                throw new RuntimeException('Project deletion was vetoed or did not remove a database row.');
            }
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'project' => 'Project records could not be deleted after media cleanup. Media may have been removed; please retry or contact support.',
            ]);
        }

        return redirect()->route('dashboard')->with('flash', [
            'success' => 'Project deleted.',
        ]);
    }

    /**
     * @return list<string>
     */
    private function mediaPathsForProject(Project $project): array
    {
        return collect([
            ...$project->photos()->pluck('path')->all(),
            ...PhotoDerivative::query()
                ->where('project_id', $project->id)
                ->pluck('storage_path')
                ->all(),
        ])
            ->filter(fn ($path): bool => $this->deletablePath($path))
            ->unique()
            ->values()
            ->all();
    }

    private function deletablePath(mixed $path): bool
    {
        return is_string($path) && $path !== '';
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteMediaOrFail(MediaStore $mediaStore, array $paths): void
    {
        foreach ($paths as $path) {
            if (! $mediaStore->delete($path) && $mediaStore->exists($path)) {
                throw new RuntimeException('Media deletion returned an unsuccessful result.');
            }
        }
    }
}
