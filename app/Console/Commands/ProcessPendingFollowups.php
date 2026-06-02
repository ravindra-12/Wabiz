<?php

namespace App\Console\Commands;

use App\Jobs\SendFollowupJob;
use App\Models\Followup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessPendingFollowups extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'followups:process';

    /**
     * The console command description.
     */
    protected $description = 'Process pending follow-ups whose scheduled_at time has arrived and dispatch them to the queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pendingFollowups = Followup::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->with('lead')
            ->get();

        if ($pendingFollowups->isEmpty()) {
            $this->info('No pending follow-ups to process.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($pendingFollowups as $followup) {
            // Skip if lead was deleted
            if (!$followup->lead) {
                Log::warning('Followup skipped — lead not found.', [
                    'followup_id' => $followup->id,
                    'lead_id' => $followup->lead_id,
                ]);
                $followup->update(['status' => 'failed']);
                $skipped++;
                continue;
            }

            SendFollowupJob::dispatch($followup);
            $dispatched++;
        }

        $this->info("Dispatched: {$dispatched} | Skipped: {$skipped}");

        Log::info('ProcessPendingFollowups completed.', [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ]);

        return self::SUCCESS;
    }
}
