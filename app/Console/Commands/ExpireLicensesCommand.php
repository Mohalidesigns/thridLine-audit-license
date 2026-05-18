<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\License;
use Illuminate\Console\Command;

class ExpireLicensesCommand extends Command
{
    protected $signature = 'licenses:expire-check';
    protected $description = 'Check and update expired licenses';

    public function handle(): int
    {
        $expired = License::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->get();

        $count = $expired->count();

        foreach ($expired as $license) {
            $license->update(['status' => 'expired']);

            AuditLog::record('license.expired', 'license', $license->id, [
                'org_id' => $license->org_id,
                'expired_at' => $license->expires_at->toISOString(),
            ], 'system');
        }

        $this->info("Marked {$count} licenses as expired.");

        return self::SUCCESS;
    }
}
