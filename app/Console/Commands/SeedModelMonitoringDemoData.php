<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\SearchLog;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports the demo datasets in database/demo_data/ — real CSV files whose
 * columns match the actual search_logs and reservations table schemas —
 * to give the Model Monitoring page (walk-forward cross-validation)
 * enough historical month spread to compute real R², RMSE, and MAE for
 * demo/defense purposes, before the mass survey generates real usage
 * data of its own.
 *
 * The two CSV files are the actual dataset artifacts:
 *   database/demo_data/model_monitoring_search_logs.csv
 *   database/demo_data/model_monitoring_reservations.csv
 * Each row's "months_ago" column is resolved to a real created_at
 * timestamp at import time, so the dataset stays valid no matter when
 * it's actually run.
 *
 * SAFE BY DESIGN:
 * - Every row this command creates is recorded (by ID) to a tracking file.
 * - The companion `model-monitoring:cleanup-demo-data` command deletes
 *   ONLY those exact IDs, never "the last N rows" — so it can never touch
 *   real data, no matter what else happens in the database afterward.
 */
class SeedModelMonitoringDemoData extends Command
{
    protected $signature = 'model-monitoring:seed-demo-data';

    protected $description = 'Import the demo datasets (database/demo_data/*.csv) so Model Monitoring has enough historical months to run walk-forward validation. Safe to undo with model-monitoring:cleanup-demo-data.';

    private string $trackingFile = 'model_monitoring_demo_ids.json';

    public function handle()
    {
        if (\Storage::exists($this->trackingFile)) {
            $this->error('A previous demo dataset is already tracked and not yet cleaned up.');
            $this->error('Run "php artisan model-monitoring:cleanup-demo-data" first, then re-run this command.');
            return 1;
        }

        $tenant = User::where('role', 'tenant')->first();
        $listing = PropertyListing::first();

        if (!$tenant || !$listing) {
            $this->error('Need at least one tenant user and one listing to attach demo reservations to. None found.');
            return 1;
        }

        $searchLogsCsv = database_path('demo_data/model_monitoring_search_logs.csv');
        $reservationsCsv = database_path('demo_data/model_monitoring_reservations.csv');

        if (!file_exists($searchLogsCsv) || !file_exists($reservationsCsv)) {
            $this->error('Dataset CSV files not found in database/demo_data/. Expected:');
            $this->error(" - {$searchLogsCsv}");
            $this->error(" - {$reservationsCsv}");
            return 1;
        }

        $this->warn("Using tenant_id={$tenant->id}, listing_id={$listing->id} for demo reservations.");

        $searchLogIds = $this->importSearchLogs($searchLogsCsv, $tenant->id);
        $reservationIds = $this->importReservations($reservationsCsv, $tenant->id, $listing->id);

        \Storage::put($this->trackingFile, json_encode([
            'created_at'       => now()->toDateTimeString(),
            'search_log_ids'   => $searchLogIds,
            'reservation_ids'  => $reservationIds,
        ], JSON_PRETTY_PRINT));

        $this->info('Done. Imported ' . count($searchLogIds) . ' search logs and ' . count($reservationIds) . ' reservations from the demo datasets.');
        $this->info('Tracking file saved: storage/app/' . $this->trackingFile);
        $this->info('Run "php artisan model-monitoring:cleanup-demo-data" any time to safely remove exactly this demo data.');

        return 0;
    }

    private function importSearchLogs(string $csvPath, int $tenantId): array
    {
        $ids = [];
        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) !== count($header)) continue; // skip malformed/blank lines
            $data = array_combine($header, $row);

            $timestamp = Carbon::now()
                ->subMonths((int) $data['months_ago'])
                ->addDays(rand(0, 25))
                ->addHours(rand(0, 23));

            $log = new SearchLog();
            $log->user_id       = $tenantId;
            $log->search_type   = $data['search_type'];
            $log->barangay      = $data['barangay'] ?: null;
            $log->budget        = $data['budget'] !== '' ? $data['budget'] : null;
            $log->property_type = $data['property_type'] ?: null;
            $log->created_at    = $timestamp;
            $log->save();

            $ids[] = $log->id;
        }

        return $ids;
    }

    private function importReservations(string $csvPath, int $tenantId, int $listingId): array
    {
        $ids = [];
        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) !== count($header)) continue;
            $data = array_combine($header, $row);

            $timestamp = Carbon::now()
                ->subMonths((int) $data['months_ago'])
                ->addDays(rand(0, 25))
                ->addHours(rand(0, 23));

            $res = new Reservation();
            $res->tenant_id  = $tenantId;
            $res->listing_id = $listingId;
            $res->status     = $data['status'];
            $res->notes      = '[MODEL MONITORING DEMO DATA — imported from CSV dataset, safe to delete via model-monitoring:cleanup-demo-data]';
            $res->save();
            // created_at/updated_at aren't in $fillable, so set directly after save()
            // (bypasses the mass-assignment guard safely, doesn't touch the model file)
            $res->created_at = $timestamp;
            $res->updated_at = $timestamp;
            $res->save();

            $ids[] = $res->id;
        }

        return $ids;
    }
}