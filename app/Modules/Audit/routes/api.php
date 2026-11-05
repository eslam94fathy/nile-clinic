<?php

use App\Modules\Audit\Http\Controllers\AuditEntryController;
use Illuminate\Support\Facades\Route;

// Audit trail (feature 011): searching only. No route writes, changes, or deletes an entry (FR-014).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/audit-entries', [AuditEntryController::class, 'index'])->name('audit.entries.index');
});
