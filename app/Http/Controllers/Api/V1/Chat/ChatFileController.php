<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChatFileController extends Controller
{
    protected FileStorageService $fileStorageService;

    public function __construct(FileStorageService $fileStorageService)
    {
        $this->fileStorageService = $fileStorageService;
    }

    /**
     * Download a chat file.
     */
    public function download(Request $request, ChatRoom $chatRoom, string $filename)
    {
        $this->authorize('view', $chatRoom);

        // Find the message with this file
        $message = \App\Models\Message::where('chat_room_id', $chatRoom->id)
            ->where('file_name', $filename)
            ->firstOrFail();

        // Check if user has access (message not deleted for them)
        if ($message->isDeletedForUser($request->user()->id) || $message->isDeletedForEveryone()) {
            abort(404, 'File not found');
        }

        // Get file path
        $filePath = Storage::disk('local')->path($message->file_path);

        if (!Storage::disk('local')->exists($message->file_path)) {
            abort(404, 'File not found');
        }

        return response()->download($filePath, $message->file_name);
    }
}

