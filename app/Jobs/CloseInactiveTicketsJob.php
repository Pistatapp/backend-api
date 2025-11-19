<?php

namespace App\Jobs;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloseInactiveTicketsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hoursToClose = 24; // Auto-close tickets 24 hours after support's last reply
        $cutoffTime = now()->subHours($hoursToClose);

        // Find tickets where support last replied more than 24 hours ago
        $tickets = Ticket::where('last_reply_by', 'support')
            ->where('last_reply_at', '<', $cutoffTime)
            ->where('status', '!=', 'closed')
            ->get();

        $closedCount = 0;

        foreach ($tickets as $ticket) {
            $ticket->close();
            $closedCount++;
        }

        if ($closedCount > 0) {
            Log::info("Closed {$closedCount} inactive tickets automatically.");
        }
    }
}

