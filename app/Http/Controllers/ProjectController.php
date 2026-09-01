<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

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
}
