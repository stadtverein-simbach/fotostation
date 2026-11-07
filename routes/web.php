<?php

use App\Http\Controllers\AltchaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

Route::get('/', [StationController::class, 'home'])->name('home');

// Upload (Ziel des QR-Codes)
Route::get('/s/{station}', [UploadController::class, 'show'])->name('upload.show');
Route::post('/s/{station}', [UploadController::class, 'store'])
    ->middleware(['throttle:uploads', ProtectAgainstSpam::class])
    ->name('upload.store');
Route::get('/s/{station}/danke', [UploadController::class, 'thanks'])
    ->middleware('signed')
    ->name('upload.thanks');

// Spamschutz: Aufgabe für das ALTCHA-Widget
Route::get('/altcha/challenge', [AltchaController::class, 'challenge'])
    ->middleware('throttle:altcha')
    ->name('altcha.challenge');

// Galerie und Zeitraffer
Route::get('/stationen', [StationController::class, 'index'])->name('stations.index');
Route::get('/stationen/{station}', [StationController::class, 'show'])->name('stations.show');

// Melden (Art. 16 DSA)
Route::get('/fotos/{submission}/melden', [ReportController::class, 'create'])->name('reports.create');
Route::post('/fotos/{submission}/melden', [ReportController::class, 'store'])
    ->middleware(['throttle:reports', ProtectAgainstSpam::class])
    ->name('reports.store');

// Pflichtseiten
Route::view('/impressum', 'pages.impressum')->name('pages.impressum');
Route::view('/datenschutz', 'pages.datenschutz')->name('pages.datenschutz');
Route::view('/nutzungsbedingungen', 'pages.nutzungsbedingungen')->name('pages.terms');
