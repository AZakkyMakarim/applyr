<?php

use App\Http\Controllers\SearchProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')->name('dashboard');

Route::resource('search-profiles', SearchProfileController::class)->except('show');
Route::patch('search-profiles/{search_profile}/pause', [SearchProfileController::class, 'pause'])->name('search-profiles.pause');
Route::patch('search-profiles/{search_profile}/resume', [SearchProfileController::class, 'resume'])->name('search-profiles.resume');
