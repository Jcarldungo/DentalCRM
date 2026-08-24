<?php

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PatientController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

// Public inquiry submission endpoint
Route::post('/contact', [InquiryController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('inquiries.store');

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/services', [PublicSiteController::class, 'services'])->name('services');
Route::get('/dentists', [PublicSiteController::class, 'dentists'])->name('dentists');
Route::get('/about', [PublicSiteController::class, 'about'])->name('about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('contact');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('providers', ProviderController::class)
        ->except(['create', 'edit', 'show']);

    Route::resource('patients', PatientController::class)
        ->except(['create', 'edit', 'show']);

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/events', [AppointmentController::class, 'events'])->name('appointments.events');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');

    Route::get('/inquiries', [AdminInquiryController::class, 'index'])->name('inquiries.index');
    Route::patch('/inquiries/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
});

require __DIR__.'/auth.php';
