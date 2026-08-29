<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id))
            ->withCount([
                'photos',
                'proposals as pending_proposals_count' => fn ($q) => $q->where('status', 'pending_review'),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'photo_count' => $p->photos_count,
                'pending_proposals' => $p->pending_proposals_count,
                'url' => route('workspace.show', $p),
            ]);

        return Inertia::render('Dashboard', [
            'projects' => $projects,
        ]);
    }
}
