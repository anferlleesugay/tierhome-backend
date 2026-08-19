<?php

namespace App\Console\Commands;

use App\Mail\ScheduledReportMail;
use App\Models\User;
use App\Models\PropertyListing;
use App\Models\Reservation;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';
    protected $description = 'Send scheduled report emails to landlords who have opted in (weekly or monthly)';

    public function handle()
    {
        $now = Carbon::now();

        $dueLandlords = User::where('role', 'landlord')
            ->where('is_active', true)
            ->whereIn('report_frequency', ['weekly', 'monthly'])
            ->get()
            ->filter(function ($landlord) use ($now) {
                if (!$landlord->last_report_sent_at) return true;
                $interval = $landlord->report_frequency === 'weekly' ? 7 : 30;
                return $landlord->last_report_sent_at->diffInDays($now) >= $interval;
            });

        $this->info("Found {$dueLandlords->count()} landlord(s) due for a report.");

        foreach ($dueLandlords as $landlord) {
            try {
                $this->sendReportTo($landlord);
                $landlord->update(['last_report_sent_at' => $now]);
                $this->info("Sent to {$landlord->email}");
            } catch (\Exception $e) {
                Log::warning("Failed to send scheduled report to user {$landlord->id}: " . $e->getMessage());
                $this->error("Failed for {$landlord->email}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function sendReportTo(User $landlord)
    {
        $periodLabel = $landlord->report_frequency === 'weekly' ? 'Weekly' : 'Monthly';
        $start = $landlord->report_frequency === 'weekly'
            ? Carbon::now()->subWeek()
            : Carbon::now()->subMonth();

        $listingIds = PropertyListing::where('landlord_id', $landlord->id)->pluck('id');

        $revenue = Reservation::whereIn('reservations.listing_id', $listingIds)
            ->where('payment_status', 'paid')
            ->where('reservations.paid_at', '>=', $start)
            ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
            ->sum('property_listings.reservation_fee');

        $expenses = Expense::whereIn('listing_id', $listingIds)
            ->where('expense_date', '>=', $start)
            ->sum('amount');

        $stats = [
            'revenue'              => (float) $revenue,
            'expenses'             => (float) $expenses,
            'net_income'           => (float) $revenue - (float) $expenses,
            'active_listings'      => PropertyListing::where('landlord_id', $landlord->id)->where('status', 'available')->count(),
            'pending_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'pending')->count(),
            'pending_maintenance'  => MaintenanceRequest::whereIn('listing_id', $listingIds)->where('status', 'pending')->count(),
        ];

        Mail::to($landlord->email)->send(new ScheduledReportMail($landlord->first_name, $periodLabel, $stats));
    }
}