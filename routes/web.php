<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LocaleController;
use App\Livewire\Admin\ExceptionIndex;
use App\Livewire\Admin\LocationEditor;
use App\Livewire\Admin\LocationIndex;
use App\Livewire\Admin\OfferingEditor;
use App\Livewire\Admin\RoleEditor;
use App\Livewire\Admin\RoleIndex;
use App\Livewire\Admin\ScheduleEditor;
use App\Livewire\Admin\ScheduleIndex;
use App\Livewire\Admin\ServiceEditor;
use App\Livewire\Admin\ServiceIndex;
use App\Livewire\Admin\SpecialistEditor;
use App\Livewire\Admin\SpecialistIndex;
use App\Livewire\Admin\UserEditor;
use App\Livewire\Admin\UserIndex;
use App\Livewire\Crm\Calendar;
use Illuminate\Support\Facades\Route;

// Public site: built in a later stage (WEB-01). Placeholder keeps "/" answering.
Route::view('/', 'welcome')->middleware('site.locale');

/*
 | Staff area. Language comes from the employee profile (LNG-02).
 */
Route::prefix('crm')->name('crm.')->middleware('staff.locale')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:30,1')->name('login.store');
        Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
        Route::get('two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.verify');
    });

    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('two-factor/verify', [TwoFactorController::class, 'challenge'])->name('two-factor.reverify');
        Route::post('two-factor/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.reverify.store');
        Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,1')->name('two-factor.confirm');

        Route::middleware('two-factor')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::post('locale', LocaleController::class)->name('locale');
            Route::livewire('calendar', Calendar::class)->name('calendar');
        });
    });
});

Route::prefix('admin')->name('admin.')->middleware(['staff.locale', 'auth', 'active', 'two-factor'])->group(function () {
    Route::livewire('locations', LocationIndex::class)->name('locations.index');
    Route::livewire('locations/create', LocationEditor::class)->name('locations.create');
    Route::livewire('locations/{location}', LocationEditor::class)->name('locations.edit');
    Route::livewire('specialists', SpecialistIndex::class)->name('specialists.index');
    Route::livewire('specialists/create', SpecialistEditor::class)->name('specialists.create');
    Route::livewire('specialists/{specialist}', SpecialistEditor::class)->name('specialists.edit');
    Route::livewire('specialists/{specialist}/services', OfferingEditor::class)->name('specialists.services');
    Route::livewire('services', ServiceIndex::class)->name('services.index');
    Route::livewire('services/create', ServiceEditor::class)->name('services.create');
    Route::livewire('services/{service}', ServiceEditor::class)->name('services.edit');
    Route::livewire('schedules', ScheduleIndex::class)->name('schedules.index');
    Route::livewire('schedules/create', ScheduleEditor::class)->name('schedules.create');
    Route::livewire('schedules/{schedule}', ScheduleEditor::class)->name('schedules.edit');
    Route::livewire('exceptions', ExceptionIndex::class)->name('exceptions.index');
    Route::livewire('users', UserIndex::class)->name('users.index');
    Route::livewire('users/create', UserEditor::class)->name('users.create');
    Route::livewire('users/{user}', UserEditor::class)->name('users.edit')->withTrashed();
    Route::livewire('roles', RoleIndex::class)->name('roles.index');
    Route::livewire('roles/create', RoleEditor::class)->name('roles.create');
    Route::livewire('roles/{role}', RoleEditor::class)->name('roles.edit');
});
