<?php

namespace App\Jobs;

use App\Models\Followup;
use App\Services\FollowupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SendFollowupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * The follow-up to send.
     */
    public Followup $followup;

    /**
     * Create a new job instance.
     */
    public function __construct(Followup $followup)
    {
        $this->followup = $followup;
        $this->onQueue('followups');
    }

    /**
     * Execute the job.
     */
    public function handle(FollowupService $followupService): void
    {
        try {
            // Skip if already sent or no longer pending
            if ($this->followup->status !== 'pending') {
                Log::info('Followup job skipped — status is not pending.', [
                    'followup_id' => $this->followup->id,
                    'current_status' => $this->followup->status,
                ]);
                return;
            }

            $followupService->sendFollowup($this->followup);

        } catch (Exception $e) {
            Log::error('SendFollowupJob failed.', [
                'followup_id' => $this->followup->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // If this is the final attempt, mark as failed
            if ($this->attempts() >= $this->tries) {
                $this->followup->update(['status' => 'failed']);
            }

            throw $e; // Re-throw so Laravel can handle retries
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void
    {
        Log::error('SendFollowupJob permanently failed.', [
            'followup_id' => $this->followup->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->followup->update(['status' => 'failed']);
    }
}
