<?php

use App\Http\Controllers\TosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TOS / Exam Generation Routes
|--------------------------------------------------------------------------
| IMPORTANT: static routes like /tos/chat and /tos/create must be defined
| BEFORE the wildcard /tos/{tos} route. Laravel matches top-to-bottom, and
| {tos} will otherwise swallow /tos/chat (treating "chat" as a TOS id and
| throwing a 404 via failed route-model-binding).
*/

Route::middleware(['web'])->group(function () {
    Route::get('/tos/create', [TosController::class, 'create'])->name('tos.create');
    Route::get('/tos/chat', function () {
        return view('tos.chat');
    })->name('tos.chat');

    // throttle:3,1 = 3 requests per minute per client. This is the heaviest
    // of the three protected endpoints — up to 15 PDF uploads (20MB each)
    // plus OCR fallback and classification per request — so it gets a
    // tighter limit than the generate-exam routes below. Same IP/user-id
    // keying behavior as those routes (see note there).
    Route::post('/tos', [TosController::class, 'classifyAndBuildTos'])
        ->middleware('throttle:3,1')
        ->name('tos.store');

    Route::get('/tos/history', [TosController::class, 'history'])->name('tos.history');

    // Wildcard route — must come AFTER the static routes above.
    Route::get('/tos/{tos}', [TosController::class, 'show'])->name('tos.show');

    Route::get('/tos/{tos}/export-pdf', [TosController::class, 'exportPdf'])->name('tos.export-pdf');
    Route::get('/tos/{tos}/export-exam-pdf', [TosController::class, 'exportExamPdf'])->name('tos.export-exam-pdf');

    // throttle:5,1 = 5 requests per minute per client. These two routes each
    // fire a real Gemini API call (with retries) per lesson, so they're the
    // ones worth protecting from accidental double-clicks or abuse. Since
    // there's no auth wired in yet, Laravel's default throttle middleware
    // limits by IP address; once auth is added it'll automatically key by
    // user id instead for logged-in requests — no change needed here.
    Route::post('/tos/{tos}/generate-exam', [TosController::class, 'generateExam'])
        ->middleware('throttle:5,1')
        ->name('tos.generate-exam');
    Route::post('/tos/{tos}/lessons/{lesson}/generate-exam', [TosController::class, 'generateExamForLesson'])
        ->middleware('throttle:5,1')
        ->name('tos.generate-lesson-exam');
});