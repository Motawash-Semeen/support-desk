<?php

use App\Modules\Conversation\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/tickets/{ticket}/replies', [ConversationController::class, 'reply'])
        ->middleware('throttle:replies')
        ->name('tickets.replies.store');

    // Downloads are checked by AttachmentPolicy.
    Route::get('/attachments/{attachment}/download', [ConversationController::class, 'download'])
        ->middleware('throttle:downloads')
        ->name('attachments.download');
});
