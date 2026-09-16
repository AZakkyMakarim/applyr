<?php

use App\Http\Controllers\AdapterController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\EducationController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\MasterProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')->name('dashboard');

Route::resource('applications', ApplicationController::class)->only(['index', 'show']);
Route::get('applications/{application}/cv', [ApplicationController::class, 'cv'])->name('applications.cv');
Route::get('applications/{application}/cover-letter', [ApplicationController::class, 'coverLetter'])->name('applications.cover-letter');
Route::put('applications/{application}/tailored-content', [ApplicationController::class, 'updateTailoredContent'])->name('applications.tailored-content.update');
Route::patch('applications/{application}/{action}', [ApplicationController::class, 'transition'])->name('applications.transition');

Route::resource('search-profiles', SearchProfileController::class)->except('show');
Route::patch('search-profiles/{search_profile}/pause', [SearchProfileController::class, 'pause'])->name('search-profiles.pause');
Route::patch('search-profiles/{search_profile}/resume', [SearchProfileController::class, 'resume'])->name('search-profiles.resume');

Route::get('master-profile', [MasterProfileController::class, 'edit'])->name('master-profile.edit');
Route::put('master-profile', [MasterProfileController::class, 'update'])->name('master-profile.update');
Route::get('master-profile/photo', [MasterProfileController::class, 'photo'])->name('master-profile.photo');
Route::delete('master-profile/photo', [MasterProfileController::class, 'destroyPhoto'])->name('master-profile.photo.destroy');

Route::prefix('master-profile')->name('master-profile.')->group(function () {
    Route::resource('experiences', ExperienceController::class)->only(['store', 'update', 'destroy']);
    Route::resource('educations', EducationController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects', ProjectController::class)->only(['store', 'update', 'destroy']);
});

Route::get('adapters', [AdapterController::class, 'index'])->name('adapters.index');
Route::patch('adapters/{platform}/resume', [AdapterController::class, 'resume'])->name('adapters.resume');
