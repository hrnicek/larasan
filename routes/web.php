<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/workspaces.php';
require __DIR__.'/projects.php';
require __DIR__.'/sections.php';
require __DIR__.'/tasks.php';
require __DIR__.'/placements.php';
require __DIR__.'/comments.php';
require __DIR__.'/files.php';
require __DIR__.'/my-tasks.php';
