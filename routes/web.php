<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Pwa\ManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/*
 * Public on purpose: the browser fetches the manifest before anybody has signed in, and an
 * install prompt that depends on a session never appears.
 */
Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/workspaces.php';
require __DIR__.'/projects.php';
require __DIR__.'/sections.php';
require __DIR__.'/pages.php';
require __DIR__.'/tasks.php';
require __DIR__.'/placements.php';
require __DIR__.'/comments.php';
require __DIR__.'/files.php';
require __DIR__.'/my-tasks.php';
require __DIR__.'/inbox.php';
require __DIR__.'/tags.php';
require __DIR__.'/custom-fields.php';
require __DIR__.'/search.php';
