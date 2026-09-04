<?php

use App\Http\Controllers\AgentConversationController;
use App\Http\Controllers\AgentConversationTurnController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\CreativeMemoryController;
use App\Http\Controllers\CreativeRoomPageController;
use App\Http\Controllers\CreativeRoomReviewController;
use App\Http\Controllers\CullingDecisionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PhotographerReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAgentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QaFindingReviewController;
use App\Http\Controllers\WebmcpDiagnosticsController;
use App\Http\Controllers\WorkspacePageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspacePageController::class, 'root'])->name('root');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('throttle:project-create')
        ->name('projects.store');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/agents', [ProjectAgentController::class, 'store'])
        ->name('projects.agents.store');

    // One main project workspace.
    Route::get('/projects/{project}', [WorkspacePageController::class, 'show'])->name('workspace.show');
    Route::get('/projects/{project}/photos/{photo}/retouch-card', [WorkspacePageController::class, 'retouchCardForPhoto'])
        ->name('workspace.retouch-card');
    Route::post('/projects/{project}/photos', [WorkspacePageController::class, 'upload'])
        ->middleware('throttle:workspace-upload')
        ->name('workspace.upload');
    Route::delete('/projects/{project}/photos/{photo}', [WorkspacePageController::class, 'destroyPhoto'])
        ->name('workspace.photos.destroy');

    // Durable project-scoped conversation. Messages are discussion only: they
    // never approve, execute, or bypass the photographer authority boundary.
    Route::get('/projects/{project}/agent-conversation/messages', [AgentConversationController::class, 'index'])
        ->name('agent-conversation.index');
    Route::post('/projects/{project}/agent-conversation/messages', [AgentConversationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('agent-conversation.store');
    Route::post('/projects/{project}/agent-conversation/turns', [AgentConversationTurnController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('agent-conversation.turn');

    // Sprint 2 — Creative Room (visual creative workspace page).
    Route::get('/projects/{project}/creative', [CreativeRoomPageController::class, 'show'])
        ->name('creative.show');

    // HUMAN-ONLY review endpoints (never exposed as WebMCP tools).
    Route::post('/projects/{project}/proposals/{proposal}/approve', [PhotographerReviewController::class, 'approve'])
        ->name('proposals.approve');
    Route::post('/projects/{project}/proposals/{proposal}/cancel', [PhotographerReviewController::class, 'cancel'])
        ->name('proposals.cancel');
    Route::post('/projects/{project}/proposals/{proposal}/reject', [PhotographerReviewController::class, 'reject'])
        ->name('proposals.reject');
    Route::post('/projects/{project}/proposals/{proposal}/revert', [PhotographerReviewController::class, 'revert'])
        ->name('proposals.revert');
    Route::post('/projects/{project}/proposals/{proposal}/modify', [PhotographerReviewController::class, 'modify'])
        ->name('proposals.modify');

    // Sprint 2 — HUMAN-ONLY Creative Room review (never WebMCP tools).
    Route::post('/projects/{project}/creative/concepts/{concept}/explore', [CreativeRoomReviewController::class, 'explore'])
        ->name('creative.concepts.explore');
    Route::post('/projects/{project}/creative/concepts/{concept}/reject', [CreativeRoomReviewController::class, 'reject'])
        ->name('creative.concepts.reject');
    Route::post('/projects/{project}/creative/concepts/{concept}/adopt', [CreativeRoomReviewController::class, 'adopt'])
        ->name('creative.concepts.adopt');
    Route::post('/projects/{project}/creative/concepts/{concept}/revise', [CreativeRoomReviewController::class, 'revise'])
        ->name('creative.concepts.revise');
    Route::post('/projects/{project}/creative/concepts/{concept}/reopen', [CreativeRoomReviewController::class, 'reopen'])
        ->name('creative.concepts.reopen');
    Route::post('/projects/{project}/creative/brainstorm', [CreativeRoomReviewController::class, 'openBrainstorm'])
        ->name('creative.brainstorm.open');

    // Sprint 3 — HUMAN-ONLY culling decision / override (never WebMCP tools).
    Route::post('/projects/{project}/culling/photos/{photo}/decide', [CullingDecisionController::class, 'decide'])
        ->name('culling.photographer-decide');

    // Sprint 4 — HUMAN-ONLY creative memory (LEARN). Never WebMCP tools.
    Route::get('/projects/{project}/creative-memories', [CreativeMemoryController::class, 'index'])
        ->name('creative-memory.index');
    Route::post('/projects/{project}/creative-memories', [CreativeMemoryController::class, 'store'])
        ->name('creative-memory.store');
    Route::delete('/projects/{project}/creative-memories/{memory}', [CreativeMemoryController::class, 'destroy'])
        ->name('creative-memory.destroy');
    Route::patch('/projects/{project}/creative-memories/{memory}', [CreativeMemoryController::class, 'update'])
        ->name('creative-memory.update');

    // Sprint 4 — HUMAN-ONLY QA actions (acknowledge / dismiss a finding).
    // The agent may analyze and explain; only the photographer decides what
    // happens to a finding. Never WebMCP tools.
    Route::post('/projects/{project}/qa-findings/{finding}/respond', [QaFindingReviewController::class, 'respond'])
        ->name('qa-findings.respond');

    // WebMCP diagnostics (development panel).
    Route::get('/webmcp-diagnostics/projects/{project}/tools', [WebmcpDiagnosticsController::class, 'tools'])
        ->name('webmcp.diagnostics.tools');

    // Breeze profile routes (restored).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // P2c — per-photographer BYO-key AI settings (key encrypted at rest,
    // never echoed back). Human-only: agents are rejected in the controller.
    Route::patch('/profile/ai-settings', [AiSettingsController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('profile.ai-settings.update');
});
