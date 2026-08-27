<?php

use App\Http\Controllers\Webmcp\BriefController;
use App\Http\Controllers\Webmcp\DecisionHistoryController;
use App\Http\Controllers\Webmcp\PhotoController;
use App\Http\Controllers\Webmcp\ProposalController;
use App\Http\Controllers\Webmcp\QaController;
use App\Http\Controllers\Webmcp\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'webmcp.agent-or-user'])->group(function () {
    Route::get('projects/{project}/workspace/context', [WorkspaceController::class, 'context'])->name('api.webmcp.context');

    Route::get('projects/{project}/photos', [PhotoController::class, 'index'])->name('api.webmcp.photos.index');
    Route::get('projects/{project}/photos/{photo}', [PhotoController::class, 'show'])->name('api.webmcp.photos.show');
    Route::get('projects/{project}/brief', [BriefController::class, 'show'])->name('api.webmcp.brief.show');
    Route::get('projects/{project}/decisions', [DecisionHistoryController::class, 'index'])->name('api.webmcp.decisions.index');

    // PROPOSE authority (never changes creative state).
    Route::post('projects/{project}/proposals/cull', [ProposalController::class, 'proposeCull'])->name('api.webmcp.proposals.cull');
    Route::post('projects/{project}/proposals/retouch-plan', [ProposalController::class, 'proposeRetouchPlan'])->name('api.webmcp.proposals.retouch');

    Route::post('projects/{project}/qa/review', [QaController::class, 'review'])->name('api.webmcp.qa.review');

    // EXECUTE authority — dynamic tool, gated server-side.
    Route::post('projects/{project}/proposals/{proposal}/execute', [ProposalController::class, 'execute'])
        ->name('api.webmcp.proposals.execute');
});
