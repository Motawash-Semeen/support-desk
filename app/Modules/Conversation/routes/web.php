<?php

use App\Modules\Conversation\Http\Controllers\AttachmentController;
use App\Modules\Conversation\Http\Controllers\TicketReplyController;
use Illuminate\Support\Facades\Route;

// L5Modular wraps this file in the 'web' middleware group.
Route::middleware(['auth', 'verified'])->group(function () {
    // The reply type is decided on the server (StoreReplyRequest::replyType).
    Route::post('/tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->middleware('throttle:replies')
        ->name('tickets.replies.store');

    // Files are streamed only through AttachmentPolicy; denied or unknown ids are 404.
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->middleware('throttle:downloads')
        ->name('attachments.download');
});
