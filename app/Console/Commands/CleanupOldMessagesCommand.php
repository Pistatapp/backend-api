<?php

namespace App\Console\Commands;

use App\Services\MessageRetentionService;
use Illuminate\Console\Command;

class CleanupOldMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:cleanup-old-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old messages based on retention policy (90 days)';

    /**
     * Execute the console command.
     */
    public function handle(MessageRetentionService $retentionService): int
    {
        $this->info('Starting message cleanup...');

        $result = $retentionService->cleanupOldMessages();

        $this->info("Soft deleted {$result['soft_deleted']} messages");
        $this->info("Hard deleted {$result['hard_deleted']} messages");

        $this->info('Message cleanup completed successfully.');

        return Command::SUCCESS;
    }
}

