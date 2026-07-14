<?php

namespace App\Jobs;

use App\Models\Tractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateTractorStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public int $uniqueFor = 5;

    public function __construct(
        public int $tractorId,
        public int $status,
    ) {
        $this->onQueue('gps-side-effects');
    }

    public function uniqueId(): string
    {
        return (string) $this->tractorId;
    }

    public function handle(): void
    {
        Tractor::where('id', $this->tractorId)->update([
            'is_working' => $this->status,
            'last_activity' => now(),
        ]);
    }
}
