<?php

use App\Http\Controllers\AgentPresenceController;
use App\Http\Controllers\Webmcp\AgentConversationController;
use App\Http\Controllers\Webmcp\BriefController;
use App\Http\Controllers\Webmcp\CreativeRoomController;
use App\Http\Controllers\Webmcp\CullingController;
use App\Http\Controllers\Webmcp\DecisionHistoryController;
use App\Http\Controllers\Webmcp\PhotoController;
use App\Http\Controllers\Webmcp\ProposalController;
use App\Http\Controllers\Webmcp\QaController;
use App\Http\Controllers\Webmcp\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'webmcp.agent-or-user'])->group(function () {
    // Project-scoped liveness is operational state, not a WebMCP tool or activity record.
    Route::get('projects/{project}/presence', [AgentPresenceController::class, 'show'])->name('api.presence.show');
    Route::post('projects/{project}/presence/heartbeat', [AgentPresenceController::class, 'heartbeat'])->name('api.presence.heartbeat');

    Route::get('projects/{project}/conversation', [AgentConversationController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('api.webmcp.conversation.index');
    Route::post('projects/{project}/conversation/replies', [AgentConversationController::class, 'reply'])
        ->middleware('throttle:30,1')
        ->name('api.webmcp.conversation.reply');

    Route::get('projects/{project}/workspace/context', [WorkspaceController::class, 'context'])->name('api.webmcp.context');

    Route::get('projects/{project}/photos', [PhotoController::class, 'index'])->name('api.webmcp.photos.index');
    Route::get('projects/{project}/photos/{photo}', [PhotoController::class, 'show'])->name('api.webmcp.photos.show');
    Route::get('projects/{project}/brief', [BriefController::class, 'show'])->name('api.webmcp.brief.show');
    Route::get('projects/{project}/decisions', [DecisionHistoryController::class, 'index'])->name('api.webmcp.decisions.index');

    // Sprint 3 — context-aware culling READ tools.
    Route::get('projects/{project}/culling/photos/{photo}/analysis', [CullingController::class, 'photoAnalysis'])
        ->name('api.webmcp.culling.photo-analysis');
    Route::get('projects/{project}/culling/context', [CullingController::class, 'cullingContext'])
        ->name('api.webmcp.culling.context');
    Route::post('projects/{project}/culling/analyze', [CullingController::class, 'analyzeProject'])
        ->name('api.webmcp.culling.analyze');

    // Sprint 2 — Creative Room READ tools.
    Route::get('projects/{project}/creative/brainstorm', [CreativeRoomController::class, 'brainstormContext'])->name('api.webmcp.creative.brainstorm');
    Route::get('projects/{project}/creative/direction', [CreativeRoomController::class, 'creativeDirection'])->name('api.webmcp.creative.direction');
    Route::get('projects/{project}/creative/concepts', [CreativeRoomController::class, 'concepts'])->name('api.webmcp.creative.concepts');
    Route::get('projects/{project}/creative/concepts/{concept}', [CreativeRoomController::class, 'concept'])->name('api.webmcp.creative.concepts.show');

    // Sprint 2 — Creative Room PROPOSE tools (never adopt / approve).
    Route::post('projects/{project}/creative/concepts', [CreativeRoomController::class, 'proposeConcepts'])->name('api.webmcp.creative.concepts');
    Route::post('projects/{project}/creative/concepts/{concept}/revise', [CreativeRoomController::class, 'proposeConceptRevision'])->name('api.webmcp.creative.concepts.revise');
    Route::post('projects/{project}/creative/merge', [CreativeRoomController::class, 'proposeConceptMerge'])->name('api.webmcp.creative.merge');
    Route::post('projects/{project}/creative/brief-proposal', [CreativeRoomController::class, 'proposeCreativeBrief'])->name('api.webmcp.creative.brief-proposal');

    // PROPOSE authority (never changes creative state).
    Route::post('projects/{project}/proposals/cull', [ProposalController::class, 'proposeCull'])->name('api.webmcp.proposals.cull');
    Route::post('projects/{project}/proposals/retouch-plan', [ProposalController::class, 'proposeRetouchPlan'])->name('api.webmcp.proposals.retouch');

    Route::post('projects/{project}/qa/review', [QaController::class, 'review'])->name('api.webmcp.qa.review');

    // EXECUTE authority — dynamic tool, gated server-side.
    Route::post('projects/{project}/proposals/{proposal}/execute', [ProposalController::class, 'execute'])
        ->name('api.webmcp.proposals.execute');
});
