<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PhotographerReviewController;
use App\Http\Controllers\WorkspacePageController;
use App\Http\Controllers\WebmcpDiagnosticsController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspacePageController::class, 'root'])->name('root');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // One main project workspace.
    Route::get('/projects/{project}', [WorkspacePageController::class, 'show'])->name('workspace.show');
    Route::post('/projects/{project}/photos', [WorkspacePageController::class, 'upload'])->name('workspace.upload');

    // HUMAN-ONLY review endpoints (never exposed as WebMCP tools).
    Route::post('/projects/{project}/proposals/{proposal}/approve', [PhotographerReviewController::class, 'approve'])
        ->name('proposals.approve');
    Route::post('/projects/{project}/proposals/{proposal}/reject', [PhotographerReviewController::class, 'reject'])
        ->name('proposals.reject');
    Route::post('/projects/{project}/proposals/{proposal}/modify', [PhotographerReviewController::class, 'modify'])
        ->name('proposals.modify');

    // WebMCP diagnostics (development panel).
    Route::get('/webmcp-diagnostics/projects/{project}/tools', [WebmcpDiagnosticsController::class, 'tools'])
        ->name('webmcp.diagnostics.tools');

    // Breeze profile routes (restored).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
