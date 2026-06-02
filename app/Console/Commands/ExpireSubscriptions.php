<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire all overdue active subscriptions whose expiry_date has passed';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $subscriptionService): int
    {
        $this->info('Checking for overdue subscriptions...');

        $count = $subscriptionService->expireOverdueSubscriptions();

        if ($count > 0) {
            $this->info("Expired {$count} overdue subscription(s).");
        } else {
            $this->info('No overdue subscriptions found.');
        }

        return self::SUCCESS;
    }
}
