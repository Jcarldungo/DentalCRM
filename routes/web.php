<?php

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DentalRecordController;
use App\Http\Controllers\Admin\PatientController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\QueueController;
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;
use App\Http\Controllers\AppointmentLookupController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

// Public inquiry submission endpoint
Route::post('/contact', [InquiryController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('inquiries.store');

// Public appointment request submission
Route::post('/book', [BookingController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('bookings.store');

// Public appointment status lookup (no account — a signed emailed link)
Route::get('/my-appointments', [AppointmentLookupController::class, 'create'])
    ->name('appointments.lookup.create');
Route::post('/my-appointments', [AppointmentLookupController::class, 'send'])
    ->middleware('throttle:6,1')
    ->name('appointments.lookup.send');
Route::get('/my-appointments/{patient}', [AppointmentLookupController::class, 'show'])
    ->middleware('signed')
    ->name('appointments.lookup.show');

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/services', [PublicSiteController::class, 'services'])->name('services');
Route::get('/dentists', [PublicSiteController::class, 'dentists'])->name('dentists');
Route::get('/about', [PublicSiteController::class, 'about'])->name('about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('contact');
Route::get('/book', [PublicSiteController::class, 'book'])->name('book');

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
        ->except(['create', 'edit']);

    Route::post('/patients/{patient}/dental-records', [DentalRecordController::class, 'store'])
        ->name('dental-records.store');

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/events', [AppointmentController::class, 'events'])->name('appointments.events');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');

    Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
    Route::post('/queue/walk-ins', [QueueController::class, 'storeWalkIn'])->name('queue.walkins.store');

    Route::get('/inquiries', [AdminInquiryController::class, 'index'])->name('inquiries.index');
    Route::patch('/inquiries/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
});

require __DIR__.'/auth.php';
