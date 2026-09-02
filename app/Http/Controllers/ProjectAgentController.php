<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Http\Requests\InviteProjectAgentRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectAgentController extends Controller
{
    public function store(InviteProjectAgentRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        /** @var string $email */
        $email = $request->validated('email');
        $agent = User::query()
            ->where('email', $email)
            ->where('is_agent', true)
            ->first();

        if ($agent === null) {
            return redirect()->route('dashboard')->withErrors([
                'email' => 'Enter the email of an existing agent account.',
            ]);
        }

        $result = DB::transaction(function () use ($project, $agent): string {
            $now = now();
            $inserted = DB::table('project_members')->insertOrIgnore([
                'project_id' => $project->id,
                'user_id' => $agent->id,
                'role' => Domain::ROLE_AGENT,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return 'invited';
            }

            $role = DB::table('project_members')
                ->where('project_id', $project->id)
                ->where('user_id', $agent->id)
                ->value('role');

            if (! is_string($role)) {
                throw new RuntimeException('Project agent membership could not be read after insertion.');
            }

            return $role === Domain::ROLE_AGENT ? 'already_attached' : 'role_conflict';
        });

        if ($result === 'role_conflict') {
            return redirect()->route('dashboard')->withErrors([
                'email' => 'This agent is already attached to the project with a different role.',
            ]);
        }

        return redirect()->route('dashboard')->with('flash', [
            'success' => $result === 'already_attached'
                ? 'Agent is already a member of this project.'
                : 'Agent invited to the project.',
        ]);
    }
}
