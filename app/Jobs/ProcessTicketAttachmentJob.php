<?php

namespace App\Jobs;

use App\Models\TicketAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessTicketAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public TicketAttachment $attachment
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $filePath = storage_path('app/' . $this->attachment->file_path);

            // Verify file exists
            if (!file_exists($filePath)) {
                Log::warning("Ticket attachment file not found: {$filePath}");
                return;
            }

            // Verify file integrity (check file size matches)
            $actualSize = filesize($filePath);
            if ($actualSize !== $this->attachment->file_size) {
                Log::warning("Ticket attachment size mismatch for attachment ID: {$this->attachment->id}");
                // Update the size in database
                $this->attachment->update(['file_size' => $actualSize]);
            }

            // For images, we could generate thumbnails here if needed
            // For now, we just verify the file is valid

            Log::info("Processed ticket attachment: {$this->attachment->id}");
        } catch (\Exception $e) {
            Log::error("Error processing ticket attachment {$this->attachment->id}: " . $e->getMessage());
            throw $e;
        }
    }
}

