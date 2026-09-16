<?php

use App\Http\Controllers\MasterProfileController;
use App\Http\Controllers\SearchProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')->name('dashboard');

Route::resource('search-profiles', SearchProfileController::class)->except('show');
Route::patch('search-profiles/{search_profile}/pause', [SearchProfileController::class, 'pause'])->name('search-profiles.pause');
Route::patch('search-profiles/{search_profile}/resume', [SearchProfileController::class, 'resume'])->name('search-profiles.resume');

Route::get('master-profile', [MasterProfileController::class, 'edit'])->name('master-profile.edit');
Route::put('master-profile', [MasterProfileController::class, 'update'])->name('master-profile.update');
Route::get('master-profile/photo', [MasterProfileController::class, 'photo'])->name('master-profile.photo');
