<?php

use App\Http\Controllers\AgentConversationController;
use App\Http\Controllers\CreativeMemoryController;
use App\Http\Controllers\CreativeRoomPageController;
use App\Http\Controllers\CreativeRoomReviewController;
use App\Http\Controllers\CullingDecisionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PhotographerReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QaFindingReviewController;
use App\Http\Controllers\WebmcpDiagnosticsController;
use App\Http\Controllers\WorkspacePageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspacePageController::class, 'root'])->name('root');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // One main project workspace.
    Route::get('/projects/{project}', [WorkspacePageController::class, 'show'])->name('workspace.show');
    Route::post('/projects/{project}/photos', [WorkspacePageController::class, 'upload'])->name('workspace.upload');

    // Durable project-scoped conversation. Messages are discussion only: they
    // never approve, execute, or bypass the photographer authority boundary.
    Route::get('/projects/{project}/agent-conversation/messages', [AgentConversationController::class, 'index'])
        ->name('agent-conversation.index');
    Route::post('/projects/{project}/agent-conversation/messages', [AgentConversationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('agent-conversation.store');

    // Sprint 2 — Creative Room (visual creative workspace page).
    Route::get('/projects/{project}/creative', [CreativeRoomPageController::class, 'show'])
        ->name('creative.show');

    // HUMAN-ONLY review endpoints (never exposed as WebMCP tools).
    Route::post('/projects/{project}/proposals/{proposal}/approve', [PhotographerReviewController::class, 'approve'])
        ->name('proposals.approve');
    Route::post('/projects/{project}/proposals/{proposal}/reject', [PhotographerReviewController::class, 'reject'])
        ->name('proposals.reject');
    Route::post('/projects/{project}/proposals/{proposal}/modify', [PhotographerReviewController::class, 'modify'])
        ->name('proposals.modify');

    // Sprint 2 — HUMAN-ONLY Creative Room review (never WebMCP tools).
    Route::post('/projects/{project}/creative/concepts/{concept}/explore', [CreativeRoomReviewController::class, 'explore'])
        ->name('creative.concepts.explore');
    Route::post('/projects/{project}/creative/concepts/{concept}/reject', [CreativeRoomReviewController::class, 'reject'])
        ->name('creative.concepts.reject');
    Route::post('/projects/{project}/creative/concepts/{concept}/adopt', [CreativeRoomReviewController::class, 'adopt'])
        ->name('creative.concepts.adopt');
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
});
