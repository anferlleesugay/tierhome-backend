<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\SearchLog;
use Illuminate\Console\Command;

/**
 * Removes EXACTLY the rows created by model-monitoring:seed-demo-data,
 * by reading their tracked IDs from storage — never "the last N rows" or
 * any other approximation that could accidentally delete real data.
 */
class CleanupModelMonitoringDemoData extends Command
{
    protected $signature = 'model-monitoring:cleanup-demo-data';
    protected $description = 'Safely delete exactly the demo data created by model-monitoring:seed-demo-data, using tracked IDs only.';

    private string $trackingFile = 'model_monitoring_demo_ids.json';

    public function handle()
    {
        if (!\Storage::exists($this->trackingFile)) {
            $this->error('No tracked demo dataset found. Nothing to clean up.');
            return 1;
        }

        $data = json_decode(\Storage::get($this->trackingFile), true);
        $searchLogIds = $data['search_log_ids'] ?? [];
        $reservationIds = $data['reservation_ids'] ?? [];

        $this->info('Found demo dataset created at: ' . ($data['created_at'] ?? 'unknown'));
        $this->info(count($searchLogIds) . ' search logs and ' . count($reservationIds) . ' reservations tracked.');

        if (!$this->confirm('Delete exactly these tracked rows now?', true)) {
            $this->warn('Cancelled — nothing deleted.');
            return 0;
        }

        $deletedLogs = SearchLog::whereIn('id', $searchLogIds)->delete();
        $deletedReservations = Reservation::whereIn('id', $reservationIds)->delete();

        \Storage::delete($this->trackingFile);

        $this->info("Deleted {$deletedLogs} search logs and {$deletedReservations} reservations.");
        $this->info('Tracking file removed. Cleanup complete.');

        return 0;
    }
}