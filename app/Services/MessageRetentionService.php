<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class MessageRetentionService
{
    /**
     * Number of days to keep messages before soft deletion.
     */
    const RETENTION_DAYS = 90;

    /**
     * Number of days to keep soft-deleted messages before hard deletion.
     */
    const HARD_DELETE_DAYS = 30;

    /**
     * Clean up old messages based on retention policy.
     *
     * @return array
     */
    public function cleanupOldMessages(): array
    {
        $softDeletedCount = 0;
        $hardDeletedCount = 0;

        // Soft delete messages older than retention period
        $softDeleteDate = now()->subDays(self::RETENTION_DAYS);
        $messagesToSoftDelete = Message::where('created_at', '<', $softDeleteDate)
            ->whereNull('deleted_at')
            ->get();

        foreach ($messagesToSoftDelete as $message) {
            $message->delete();
            $softDeletedCount++;
        }

        // Hard delete messages that were soft-deleted more than HARD_DELETE_DAYS ago
        $hardDeleteDate = now()->subDays(self::RETENTION_DAYS + self::HARD_DELETE_DAYS);
        $messagesToHardDelete = Message::onlyTrashed()
            ->where('deleted_at', '<', $hardDeleteDate)
            ->get();

        foreach ($messagesToHardDelete as $message) {
            // Delete associated file if exists
            if ($message->isFile() && $message->file_path) {
                $fileStorageService = app(FileStorageService::class);
                $fileStorageService->deleteFile($message->file_path);
            }

            // Permanently delete the message
            $message->forceDelete();
            $hardDeletedCount++;
        }

        return [
            'soft_deleted' => $softDeletedCount,
            'hard_deleted' => $hardDeletedCount,
        ];
    }
}

