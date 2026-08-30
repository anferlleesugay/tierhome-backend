<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\PropertyListing;
use App\Models\User;
use App\Models\IdentityVerification;
use App\Models\SearchLog;
use App\Models\Message;
use App\Models\Conversation;
use App\Services\ForecastingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * ADMIN: Platform-wide analytics
     */
    public function adminOverview()
    {
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            // Monthly reservation counts (last 6 months, including current)
            $reservationsByMonth = Reservation::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // ✅ Monthly search/demand activity — the real "tenants looking for a place" signal
            $searchesByMonth = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            // Most-searched barangays (top 5) — useful demand-location insight
            $topBarangays = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereNotNull('barangay')
                ->selectRaw('barangay, COUNT(*) as count')
                ->groupBy('barangay')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            // Monthly new user registrations
            $usersByMonth = User::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $userMonthlyData = $this->fillMissingMonths($usersByMonth, $sixMonthsAgo);

            // Summary counts
            $totalUsers        = User::count();
            $totalTenants       = User::where('role', 'tenant')->count();
            $totalLandlords     = User::where('role', 'landlord')->count();
            $totalListings      = PropertyListing::count();
            $verifiedListings   = PropertyListing::where('is_verified', true)->count();
            $totalReservations  = Reservation::count();
            $pendingReservations = Reservation::where('status', 'pending')->count();
            $approvedReservations = Reservation::where('status', 'approved')->count();
            $totalSearches      = SearchLog::count();

            $verifiedUsers = IdentityVerification::where('status', 'approved')->count();
            $pendingVerifications = IdentityVerification::where('status', 'pending')->count();

            $statusBreakdown = Reservation::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get();

            // ✅ Multi-month forecast (3 months forward) based on search/demand activity
            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                'platform-wide tenant demand (searches, filters, and affordability checks) and actual reservations',
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_users'            => $totalUsers,
                        'total_tenants'           => $totalTenants,
                        'total_landlords'         => $totalLandlords,
                        'total_listings'          => $totalListings,
                        'verified_listings'       => $verifiedListings,
                        'total_reservations'      => $totalReservations,
                        'pending_reservations'    => $pendingReservations,
                        'approved_reservations'   => $approvedReservations,
                        'verified_users'          => $verifiedUsers,
                        'pending_verifications'   => $pendingVerifications,
                        'total_searches'          => $totalSearches,
                    ],
                    'reservations_by_month' => $monthlyData,
                    'searches_by_month'     => $searchMonthlyData,
                    'top_barangays'         => $topBarangays,
                    'users_by_month'        => $userMonthlyData,
                    'status_breakdown'      => $statusBreakdown,
                    'forecast' => [
                        'demand_next_3_months'      => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                     => $insight,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOverview analytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * LANDLORD: Analytics for their own listings
     */
    public function landlordOverview()
    {
        try {
            $landlordId = auth()->id();
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');
            $landlordBarangays = PropertyListing::where('landlord_id', $landlordId)
                ->pluck('barangay')
                ->filter()
                ->map(fn($b) => trim(str_ireplace('barangay', '', $b)))
                ->unique();

            $reservationsByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // ✅ Demand signal specific to this landlord's barangay(s) — how many tenants are searching in their area(s)
            $searchesByMonth = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereIn(\DB::raw("TRIM(REPLACE(LOWER(barangay), 'barangay', ''))"), $landlordBarangays->map(fn($b) => strtolower(trim($b))))
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            $totalRevenue = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->where('reservations.created_at', '>=', $sixMonthsAgo)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->selectRaw("DATE_FORMAT(reservations.created_at, '%Y-%m') as month, SUM(property_listings.reservation_fee) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $revenueMonthlyData = $this->fillMissingMonths($revenueByMonth, $sixMonthsAgo, 'total');

            $listingPerformance = PropertyListing::where('landlord_id', $landlordId)
                ->withCount(['reservations as total_reservations'])
                ->withCount(['reservations as approved_reservations' => function ($q) {
                    $q->where('status', 'approved');
                }])
                ->with('images')
                ->get(['id', 'title', 'price', 'status', 'barangay', 'is_verified', 'reservation_fee']);

            // ✅ Per-listing revenue — previously only a landlord-wide total existed, with no way
            // to see WHICH listing actually earns more. Client 2 (Q13) specifically wanted a
            // "property performance comparison."
            $paidCountsByListing = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->selectRaw('listing_id, COUNT(*) as paid_count')
                ->groupBy('listing_id')
                ->pluck('paid_count', 'listing_id');

            $listingPerformance->each(function ($l) use ($paidCountsByListing) {
                $paid = $paidCountsByListing[$l->id] ?? 0;
                $l->revenue = (float) ($paid * ($l->reservation_fee ?? 0));
            });

            // ── Itemized paid-reservation breakdown — the exact transactions behind the
            // owner's total_revenue figure, matching the admin-facing equivalent.
            $paidReservations = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->with(['tenant:id,first_name,last_name', 'listing:id,title,reservation_fee'])
                ->orderByDesc('paid_at')
                ->get()
                ->map(function ($r) {
                    return [
                        'id'             => $r->id,
                        'tenant_name'    => trim(($r->tenant->first_name ?? '') . ' ' . ($r->tenant->last_name ?? '')) ?: 'Unknown tenant',
                        'listing_title'  => $r->listing->title ?? 'Unknown listing',
                        'amount'         => (float) ($r->listing->reservation_fee ?? 0),
                        'paid_at'        => $r->paid_at,
                    ];
                });

            // ✅ Upcoming Vacancies — Client 2 (Q12) explicitly named this alongside occupancy
            // rate as something they currently have to guess at. Approved reservations whose
            // move-out date falls in the next 30 days mean that unit is about to free up.
            $vacancyWindow = Carbon::now()->addDays(30)->toDateString();
            $today = Carbon::now()->toDateString();
            $upcomingVacancies = Reservation::whereIn('listing_id', $listingIds)
                ->where('status', 'approved')
                ->whereNotNull('move_out_date')
                ->whereBetween('move_out_date', [$today, $vacancyWindow])
                ->with('listing:id,title,barangay')
                ->orderBy('move_out_date')
                ->get()
                ->filter(fn($r) => $r->listing)
                ->map(fn($r) => [
                    'listing_title' => $r->listing->title,
                    'barangay'      => $r->listing->barangay,
                    'move_out_date' => $r->move_out_date?->format('Y-m-d'),
                ]);

            $summary = [
                'total_listings'       => $listingIds->count(),
                'total_reservations'   => Reservation::whereIn('listing_id', $listingIds)->count(),
                'pending_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'pending')->count(),
                'approved_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'approved')->count(),
                'total_revenue'        => (float) $totalRevenue,
                'area_searches'        => $searchMonthlyData->sum('count'),
            ];

            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                'tenant search activity in your listing areas and actual reservation requests',
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            // ✅ Forecast Reliability — plain-language translation of the same walk-forward
            // validation MAE shown to Admin on Model Monitoring, so Property Owners can see
            // how trustworthy the forecast has historically been, without needing to
            // understand R²/RMSE. This reflects the model's overall platform-wide accuracy,
            // not this owner's specific listings — individual owners rarely have enough of
            // their own reservation history to validate separately.
            $reservationReliability = $this->walkForwardValidate(
                Reservation::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                    ->groupBy('month')->orderBy('month')->pluck('count')->toArray(),
                3
            );
            $forecastReliability = $reservationReliability['n'] >= 3
                ? "Our forecasting model has historically predicted within about ± {$reservationReliability['mae']} reservations of the actual result, platform-wide."
                : null; // not enough historical months yet — omit rather than show a misleading figure

            return response()->json([
                'success' => true,
                'data' => [
                    'summary'                => $summary,
                    'reservations_by_month'  => $monthlyData,
                    'searches_by_month'      => $searchMonthlyData,
                    'revenue_by_month'       => $revenueMonthlyData,
                    'listing_performance'    => $listingPerformance,
                    'paid_reservations'      => $paidReservations,
                    'upcoming_vacancies'     => $upcomingVacancies,
                    'forecast' => [
                        'demand_next_3_months'       => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                      => $insight,
                        'reliability_note'            => $forecastReliability,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('LandlordOverview analytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * LANDLORD: Dashboard widgets — key stats, pending-task tracker, and a combined
     * activity feed (reservations + messages). Built directly from the property owner
     * interview findings: both respondents independently asked for these three things.
     */
    public function landlordDashboardWidgets()
    {
        try {
            $landlordId = auth()->id();
            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

            $startOfMonth     = Carbon::now()->startOfMonth();
            $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
            $endOfLastMonth   = Carbon::now()->subMonth()->endOfMonth();
            $checkInWindow    = Carbon::now()->addDays(14)->toDateString();
            $today            = Carbon::now()->toDateString();

            // ── KEY STATS ── (Active Listings / Occupancy Rate / Reservation Volume / Revenue —
            // matches Client 2's exact "healthy at a glance" spec, including month-over-month comparison)
            $totalListingsCount = PropertyListing::where('landlord_id', $landlordId)->count();
            $occupiedListingsCount = PropertyListing::where('landlord_id', $landlordId)
                ->where('status', 'unavailable')->count();
            $occupancyRatePct = $totalListingsCount > 0
                ? round(($occupiedListingsCount / $totalListingsCount) * 100)
                : null;

            $revenueThisMonth = (float) Reservation::whereIn('reservations.listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->where('reservations.paid_at', '>=', $startOfMonth)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueLastMonth = (float) Reservation::whereIn('reservations.listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->whereBetween('reservations.paid_at', [$startOfLastMonth, $endOfLastMonth])
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueChangePct = $revenueLastMonth > 0
                ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100)
                : null;

            $reservationsThisMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('created_at', '>=', $startOfMonth)->count();
            $reservationsLastMonth = Reservation::whereIn('listing_id', $listingIds)
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count();
            $reservationsChangePct = $reservationsLastMonth > 0
                ? round((($reservationsThisMonth - $reservationsLastMonth) / $reservationsLastMonth) * 100)
                : null;

            $stats = [
                'active_listings'       => PropertyListing::where('landlord_id', $landlordId)
                    ->where('status', 'available')->count(),
                'occupancy_rate'        => $occupancyRatePct,
                'pending_reservations'  => Reservation::whereIn('listing_id', $listingIds)
                    ->where('status', 'pending')->count(),
                'reservations_this_month'   => $reservationsThisMonth,
                'reservations_change_pct'   => $reservationsChangePct,
                'revenue_this_month'    => $revenueThisMonth,
                'revenue_change_pct'    => $revenueChangePct,
                'upcoming_checkins'     => Reservation::whereIn('listing_id', $listingIds)
                    ->where('status', 'approved')
                    ->whereBetween('move_in_date', [$today, $checkInWindow])
                    ->count(),
            ];

            // ── TASK TRACKER ── (both interview respondents asked for this specifically)
            $tasks = [
                'pending_reservations' => $stats['pending_reservations'],
                'pending_listing_approvals' => PropertyListing::where('landlord_id', $landlordId)
                    ->where('is_verified', false)
                    ->where('status', '!=', 'rejected')
                    ->count(),
                'unread_messages' => Message::whereHas('conversation', fn($q) => $q->where('landlord_id', $landlordId))
                    ->where('sender_id', '!=', $landlordId)
                    ->whereNull('read_at')
                    ->count(),
                'pending_maintenance' => \App\Models\MaintenanceRequest::whereIn('listing_id', $listingIds)
                    ->where('status', 'pending')
                    ->count(),
            ];

            // ── ACTIVITY FEED ── (last 10 combined events — reservations + messages)
            $recentReservations = Reservation::whereIn('listing_id', $listingIds)
                ->with(['tenant:id,first_name,last_name', 'listing:id,title'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->filter(fn($r) => $r->tenant && $r->listing)
                ->map(fn($r) => [
                    'type'      => 'reservation',
                    'message'   => "{$r->tenant->first_name} {$r->tenant->last_name} requested to reserve \"{$r->listing->title}\"",
                    'status'    => $r->status,
                    'timestamp' => $r->created_at,
                ]);

            $recentMessages = Message::whereHas('conversation', fn($q) => $q->where('landlord_id', $landlordId))
                ->where('sender_id', '!=', $landlordId)
                ->with(['sender:id,first_name,last_name', 'conversation:id,listing_id', 'conversation.listing:id,title'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->filter(fn($m) => $m->sender)
                ->map(fn($m) => [
                    'type'      => 'message',
                    'message'   => "{$m->sender->first_name} {$m->sender->last_name} sent a message"
                        . ($m->conversation && $m->conversation->listing ? " about \"{$m->conversation->listing->title}\"" : ""),
                    'status'    => null,
                    'timestamp' => $m->created_at,
                ]);

            $activityFeed = $recentReservations->concat($recentMessages)
                ->sortByDesc('timestamp')
                ->take(10)
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats'         => $stats,
                    'tasks'         => $tasks,
                    'activity_feed' => $activityFeed,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('landlordDashboardWidgets error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: Dashboard widgets — mirrors landlordDashboardWidgets but platform-wide.
     * Spec sourced directly from Client 2 interview Q16 (healthy-platform numbers:
     * Active Users | Active Listings | Reservations | Platform Revenue, with MoM comparison)
     * and Q6/Q9 (task tracker) + Q8 (live activity feed).
     */
    public function adminDashboardWidgets()
    {
        try {
            $startOfMonth     = Carbon::now()->startOfMonth();
            $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
            $endOfLastMonth   = Carbon::now()->subMonth()->endOfMonth();

            // ── KEY STATS ── Active Users / Active Listings / Reservations / Platform Revenue, w/ MoM
            // Excludes admin accounts — this stat is meant to reflect real platform users
            // (tenants + property owners), not internal admin staff.
            $activeUsersCount = User::where('is_active', true)->where('role', '!=', 'admin')->count();

            $activeListingsCount = PropertyListing::where('status', 'available')->count();

            $reservationsThisMonth = Reservation::where('created_at', '>=', $startOfMonth)->count();
            $reservationsLastMonth = Reservation::whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count();
            $reservationsChangePct = $reservationsLastMonth > 0
                ? round((($reservationsThisMonth - $reservationsLastMonth) / $reservationsLastMonth) * 100)
                : null;

            $revenueThisMonth = (float) Reservation::where('payment_status', 'paid')
                ->where('paid_at', '>=', $startOfMonth)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueLastMonth = (float) Reservation::where('payment_status', 'paid')
                ->whereBetween('paid_at', [$startOfLastMonth, $endOfLastMonth])
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueChangePct = $revenueLastMonth > 0
                ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100)
                : null;

            // ── Moved here from Admin Analytics, which duplicated these against the
            // quick-stats above — Total Searches has no prior home on the Dashboard,
            // and the moderation-overview numbers give the admin a fuller at-a-glance
            // picture without needing to visit Analytics separately.
            $totalSearches = SearchLog::count();

            $verifiedUsersCount = IdentityVerification::where('status', 'approved')
                ->whereNotNull('face_id')->count();

            $conversationsCount = Conversation::where('tenant_id', auth()->id())
                ->orWhere('landlord_id', auth()->id())->count();

            $stats = [
                'active_users'             => $activeUsersCount,
                'active_listings'          => $activeListingsCount,
                'reservations_this_month'  => $reservationsThisMonth,
                'reservations_change_pct'  => $reservationsChangePct,
                'revenue_this_month'       => $revenueThisMonth,
                'revenue_change_pct'       => $revenueChangePct,
                'total_searches'           => $totalSearches,
            ];

            // ── MODERATION OVERVIEW ── reuses the pending counts already computed
            // below in $tasks rather than re-querying them.
            $moderation = [
                'verified_users'         => $verifiedUsersCount,
                'pending_verifications'  => IdentityVerification::where('status', 'pending')->count(),
                'pending_listings'       => PropertyListing::where('is_verified', false)
                    ->where('status', '!=', 'rejected')->count(),
                'conversations'          => $conversationsCount,
            ];

            // ── TASK TRACKER ── everything an admin needs to act on right now
            $tasks = [
                'pending_verifications'     => IdentityVerification::where('status', 'pending')->count(),
                'pending_listing_approvals' => PropertyListing::where('is_verified', false)
                    ->where('status', '!=', 'rejected')->count(),
                'pending_amenity_requests'  => \App\Models\AmenityRequest::where('status', 'pending')->count(),
                'pending_deactivation_requests' => \App\Models\DeactivationRequest::where('status', 'pending')->count(),
            ];

            // ── ACTIVITY FEED ── last 10 platform-wide events (new users, listings, reservations, admin actions)
            $recentUsers = User::orderBy('created_at', 'desc')->limit(10)
                ->get(['id', 'first_name', 'last_name', 'role', 'created_at'])
                ->map(fn($u) => [
                    'type'      => 'user',
                    'message'   => "{$u->first_name} {$u->last_name} registered as " . ucfirst($u->role === 'landlord' ? 'Property Owner' : $u->role),
                    'status'    => null,
                    'timestamp' => $u->created_at,
                ]);

            $recentListings = PropertyListing::orderBy('created_at', 'desc')->limit(10)
                ->with('landlord:id,first_name,last_name')
                ->get(['id', 'title', 'landlord_id', 'created_at'])
                ->filter(fn($l) => $l->landlord)
                ->map(fn($l) => [
                    'type'      => 'listing',
                    'message'   => "{$l->landlord->first_name} {$l->landlord->last_name} submitted \"{$l->title}\"",
                    'status'    => null,
                    'timestamp' => $l->created_at,
                ]);

            $recentReservations = Reservation::orderBy('created_at', 'desc')->limit(10)
                ->with(['tenant:id,first_name,last_name', 'listing:id,title'])
                ->get()
                ->filter(fn($r) => $r->tenant && $r->listing)
                ->map(fn($r) => [
                    'type'      => 'reservation',
                    'message'   => "{$r->tenant->first_name} {$r->tenant->last_name} reserved \"{$r->listing->title}\"",
                    'status'    => $r->status,
                    'timestamp' => $r->created_at,
                ]);

            $recentAdminActions = \App\Models\AuditLog::orderBy('created_at', 'desc')->limit(10)
                ->get(['id', 'description', 'created_at'])
                ->map(fn($a) => [
                    'type'      => 'admin_action',
                    'message'   => $a->description,
                    'status'    => null,
                    'timestamp' => $a->created_at,
                ]);

            $activityFeed = $recentUsers->concat($recentListings)
                ->concat($recentReservations)
                ->concat($recentAdminActions)
                ->sortByDesc('timestamp')
                ->take(10)
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats'         => $stats,
                    'tasks'         => $tasks,
                    'moderation'    => $moderation,
                    'activity_feed' => $activityFeed,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('adminDashboardWidgets error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: Model Monitoring — walk-forward cross-validation of the Linear
     * Regression forecasting model, reporting R², RMSE, and MAE.
     *
     * Walk-forward validation respects the chronological order of time-series
     * data (unlike a random k-fold split, which would let the model "see"
     * future months during training): for each split point, the model is
     * trained only on months *before* that point and predicts the next
     * month forward — exactly matching how the live dashboard forecast is
     * actually generated — then the prediction is compared against what
     * really happened.
     */
    public function modelMonitoring()
    {
        try {
            $minTrainMonths = 3; // need at least 3 points to fit a meaningful line

            // ── Pull ALL monthly search (demand) and reservation counts —
            // not capped to the last 6 months like the dashboard forecast,
            // since validation benefits from every historical month available ──
            $searchByMonth = SearchLog::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')->orderBy('month')->get();

            $reservationByMonth = Reservation::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')->orderBy('month')->get();

            $demandMetrics = $this->walkForwardValidate($searchByMonth->pluck('count')->toArray(), $minTrainMonths);
            $reservationMetrics = $this->walkForwardValidate($reservationByMonth->pluck('count')->toArray(), $minTrainMonths);

            return response()->json([
                'success' => true,
                'data' => [
                    'demand_forecast_model' => [
                        'metric_label'           => 'Tenant Demand (Search Activity) Forecast',
                        'total_months_available' => count($searchByMonth),
                        'validation_points_used' => $demandMetrics['n'],
                        'r_squared'              => $demandMetrics['r2'],
                        'rmse'                   => $demandMetrics['rmse'],
                        'mae'                    => $demandMetrics['mae'],
                        'predictions'            => $demandMetrics['pairs'],
                        'sufficient_data'        => $demandMetrics['n'] >= 3,
                    ],
                    'reservation_forecast_model' => [
                        'metric_label'           => 'Reservation Volume Forecast',
                        'total_months_available' => count($reservationByMonth),
                        'validation_points_used' => $reservationMetrics['n'],
                        'r_squared'              => $reservationMetrics['r2'],
                        'rmse'                   => $reservationMetrics['rmse'],
                        'mae'                    => $reservationMetrics['mae'],
                        'predictions'            => $reservationMetrics['pairs'],
                        'sufficient_data'        => $reservationMetrics['n'] >= 3,
                    ],
                    'methodology' => 'Walk-forward validation: the model is trained only on months prior to '
                        . 'each prediction point (never future months), then evaluated against the actual '
                        . 'outcome for that month. This respects the chronological order of time-series data, '
                        . 'unlike random k-fold splitting, which would leak future information into training.',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('modelMonitoring error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Walk-forward cross-validation for the Linear Regression forecaster.
     * For each valid split point, trains on months [0..split-1] and predicts
     * month [split], then compares the prediction against the real value.
     * Returns R² (goodness of fit vs. the mean baseline), RMSE, and MAE
     * computed across every walk-forward prediction made.
     */
    private function walkForwardValidate(array $values, int $minTrainMonths = 3): array
    {
        return (new ForecastingService())->walkForwardValidate($values, $minTrainMonths);
    }

    public function landlordOverviewForAdmin($landlordId)
    {
        try {
            $landlord = User::where('id', $landlordId)->where('role', 'landlord')->firstOrFail();
 
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
 
            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');
            $landlordBarangays = PropertyListing::where('landlord_id', $landlordId)->pluck('barangay')->filter()->unique();
 
            $reservationsByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);
 
            $searchesByMonth = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereIn(\DB::raw("TRIM(REPLACE(LOWER(barangay), 'barangay', ''))"), $landlordBarangays->map(fn($b) => strtolower(trim($b))))
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);
 
            $totalRevenue = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');
 
            $revenueByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->where('reservations.created_at', '>=', $sixMonthsAgo)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->selectRaw("DATE_FORMAT(reservations.created_at, '%Y-%m') as month, SUM(property_listings.reservation_fee) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $revenueMonthlyData = $this->fillMissingMonths($revenueByMonth, $sixMonthsAgo, 'total');
 
            $listingPerformance = PropertyListing::where('landlord_id', $landlordId)
                ->withCount(['reservations as total_reservations'])
                ->withCount(['reservations as approved_reservations' => function ($q) {
                    $q->where('status', 'approved');
                }])
                ->with('images')
                ->get(['id', 'title', 'price', 'status', 'barangay', 'is_verified']);

            // ── Itemized paid-reservation breakdown — the exact transactions that make up
            // this owner's total_revenue figure, so an admin can see where the money came from
            // rather than just a lump sum.
            $paidReservations = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->with(['tenant:id,first_name,last_name', 'listing:id,title,reservation_fee'])
                ->orderByDesc('paid_at')
                ->get()
                ->map(function ($r) {
                    return [
                        'id'             => $r->id,
                        'tenant_name'    => trim(($r->tenant->first_name ?? '') . ' ' . ($r->tenant->last_name ?? '')) ?: 'Unknown tenant',
                        'listing_title'  => $r->listing->title ?? 'Unknown listing',
                        'amount'         => (float) ($r->listing->reservation_fee ?? 0),
                        'paid_at'        => $r->paid_at,
                        'transaction_id' => $r->paymongo_payment_intent_id,
                    ];
                });
 
            $summary = [
                'total_listings'       => $listingIds->count(),
                'total_reservations'   => Reservation::whereIn('listing_id', $listingIds)->count(),
                'pending_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'pending')->count(),
                'approved_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'approved')->count(),
                'total_revenue'        => (float) $totalRevenue,
                'area_searches'        => $searchMonthlyData->sum('count'),
            ];
 
            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);
 
            $insight = $this->generateInsight(
                "tenant search activity and reservation requests for {$landlord->first_name} {$landlord->last_name}'s listings",
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );
 
            return response()->json([
                'success' => true,
                'data' => [
                    'landlord'               => $landlord->only(['id', 'first_name', 'last_name', 'email', 'profile_picture']),
                    'summary'                => $summary,
                    'reservations_by_month'  => $monthlyData,
                    'searches_by_month'      => $searchMonthlyData,
                    'revenue_by_month'       => $revenueMonthlyData,
                    'listing_performance'    => $listingPerformance,
                    'paid_reservations'      => $paidReservations,
                    'forecast' => [
                        'demand_next_3_months'       => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                      => $insight,
                    ],
                ],
            ]);
 
        } catch (\Exception $e) {
            Log::error('landlordOverviewForAdmin error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
 
    /**
     * Per-listing analytics — accessible by admin (any listing) or the owning landlord (their own only).
     * Note: tenant search demand is only ever logged at the barangay level (see SearchLog), never
     * per-listing, so "area context" here reflects the listing's barangay, not the listing itself.
     */
    public function listingAnalytics($id)
    {
        try {
            $listing = PropertyListing::with('images')->findOrFail($id);

            $user = auth()->user();
            if ($user->role !== 'admin' && (int) $listing->landlord_id !== (int) $user->id) {
                return response()->json(['success' => false, 'message' => 'You are not authorized to view this listing\'s analytics.'], 403);
            }

            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            $reservationsByMonth = Reservation::where('listing_id', $id)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // Area-level context only — searches are logged per barangay, not per listing
            $barangayNormalized = strtolower(trim(str_ireplace('barangay', '', $listing->barangay ?? '')));
            $searchesByMonth = $barangayNormalized !== '' ? SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereRaw("TRIM(REPLACE(LOWER(barangay), 'barangay', '')) = ?", [$barangayNormalized])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get() : collect();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            $fee = (float) ($listing->reservation_fee ?? 0);
            $paidByMonth = Reservation::where('listing_id', $id)
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $revenueMonthlyData = $this->fillMissingMonths($paidByMonth, $sixMonthsAgo)
                ->map(fn($row) => ['month' => $row['month'], 'count' => $row['count'] * $fee]);

            $savedCount = \App\Models\SavedListing::where('listing_id', $id)->count();

            $summary = [
                'total_reservations'    => Reservation::where('listing_id', $id)->count(),
                'pending_reservations'  => Reservation::where('listing_id', $id)->where('status', 'pending')->count(),
                'approved_reservations' => Reservation::where('listing_id', $id)->where('status', 'approved')->count(),
                'rejected_reservations' => Reservation::where('listing_id', $id)->where('status', 'rejected')->count(),
                'total_revenue'         => (float) (Reservation::where('listing_id', $id)->where('payment_status', 'paid')->count() * $fee),
                'saved_count'           => $savedCount,
                'area_searches'         => $searchMonthlyData->sum('count'),
            ];

            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                "tenant interest and reservation activity specifically for the listing \"{$listing->title}\"",
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'listing'               => $listing->only(['id', 'title', 'price', 'status', 'barangay', 'is_verified', 'property_type', 'reservation_fee']),
                    'images'                => $listing->images,
                    'summary'               => $summary,
                    'reservations_by_month' => $monthlyData,
                    'searches_by_month'     => $searchMonthlyData,
                    'revenue_by_month'      => $revenueMonthlyData,
                    'forecast' => [
                        'demand_next_3_months'       => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                     => $insight,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('listingAnalytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: every listing platform-wide (regardless of owner), with quick stats each.
     */
    public function allListingsSummary()
    {
        try {
            $listings = PropertyListing::with(['landlord:id,first_name,last_name,email', 'images'])
                ->withCount(['reservations as total_reservations'])
                ->withCount(['reservations as approved_reservations' => function ($q) {
                    $q->where('status', 'approved');
                }])
                ->withCount(['reservations as pending_reservations' => function ($q) {
                    $q->where('status', 'pending');
                }])
                ->get(['id', 'title', 'landlord_id', 'price', 'status', 'barangay', 'is_verified', 'reservation_fee']);

            $paidCounts = Reservation::where('payment_status', 'paid')
                ->selectRaw('listing_id, COUNT(*) as paid_count')
                ->groupBy('listing_id')
                ->pluck('paid_count', 'listing_id');

            $savedCounts = \App\Models\SavedListing::selectRaw('listing_id, COUNT(*) as cnt')
                ->groupBy('listing_id')
                ->pluck('cnt', 'listing_id');

            $listings->each(function ($listing) use ($paidCounts, $savedCounts) {
                $paid = $paidCounts[$listing->id] ?? 0;
                $listing->total_revenue = (float) ($paid * ($listing->reservation_fee ?? 0));
                $listing->saved_count   = $savedCounts[$listing->id] ?? 0;
            });

            $summary = [
                'total_listings'      => $listings->count(),
                'verified_listings'   => $listings->where('is_verified', true)->count(),
                'total_reservations'  => (int) $listings->sum('total_reservations'),
                'total_revenue'       => (float) $listings->sum('total_revenue'),
            ];

            return response()->json(['success' => true, 'data' => ['listings' => $listings, 'summary' => $summary]]);

        } catch (\Exception $e) {
            Log::error('allListingsSummary error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: platform-wide revenue broken down BY OWNER — Client 2's exact spec:
     * "by month, by property, by owner, plus commission/fees." Commission isn't
     * modeled in TierHome (no platform fee exists), so this covers revenue-by-owner
     * with month-over-month comparison, which is the buildable core of that ask.
     */
    public function revenueByOwner()
    {
        try {
            $startOfMonth     = Carbon::now()->startOfMonth();
            $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
            $endOfLastMonth   = Carbon::now()->subMonth()->endOfMonth();

            $landlords = User::where('role', 'landlord')
                ->get(['id', 'first_name', 'last_name', 'email']);

            $ownerData = $landlords->map(function ($landlord) use ($startOfMonth, $startOfLastMonth, $endOfLastMonth) {
                $listingIds = PropertyListing::where('landlord_id', $landlord->id)->pluck('id');

                $totalRevenue = Reservation::whereIn('reservations.listing_id', $listingIds)
                    ->where('payment_status', 'paid')
                    ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                    ->sum('property_listings.reservation_fee');

                $thisMonthRevenue = Reservation::whereIn('reservations.listing_id', $listingIds)
                    ->where('payment_status', 'paid')
                    ->where('reservations.paid_at', '>=', $startOfMonth)
                    ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                    ->sum('property_listings.reservation_fee');

                $lastMonthRevenue = Reservation::whereIn('reservations.listing_id', $listingIds)
                    ->where('payment_status', 'paid')
                    ->whereBetween('reservations.paid_at', [$startOfLastMonth, $endOfLastMonth])
                    ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                    ->sum('property_listings.reservation_fee');

                $changePct = $lastMonthRevenue > 0
                    ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100)
                    : null;

                return [
                    'id'                 => $landlord->id,
                    'name'               => "{$landlord->first_name} {$landlord->last_name}",
                    'email'              => $landlord->email,
                    'total_listings'     => $listingIds->count(),
                    'total_revenue'      => (float) $totalRevenue,
                    'revenue_this_month' => (float) $thisMonthRevenue,
                    'revenue_change_pct' => $changePct,
                ];
            })
            ->filter(fn($o) => $o['total_revenue'] > 0)
            ->sortByDesc('total_revenue')
            ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'owners'           => $ownerData,
                    'platform_total'   => (float) $ownerData->sum('total_revenue'),
                    'platform_this_month' => (float) $ownerData->sum('revenue_this_month'),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('revenueByOwner error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: Full platform revenue report — directly sourced from Client 2 interview Q18,
     * which asked for revenue by month, by property, transaction volume, refunds, and
     * outstanding payments, on top of the by-owner breakdown already in revenueByOwner().
     */
    public function platformRevenueReport()
    {
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
            $startOfMonth = Carbon::now()->startOfMonth();

            // ── Revenue by month (last 6 months) ──
            $revenueByMonth = Reservation::where('payment_status', 'paid')
                ->where('paid_at', '>=', $sixMonthsAgo)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->selectRaw("DATE_FORMAT(reservations.paid_at, '%Y-%m') as month, SUM(property_listings.reservation_fee) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'total' => (float) $r->total]);

            // ── Revenue by property (top 10 earning listings platform-wide) ──
            // Rewritten as a plain join+groupBy (same proven pattern as revenueByMonth above)
            // instead of withCount, to eliminate any risk of a silent query failure here.
            $revenueByProperty = Reservation::where('payment_status', 'paid')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->join('users as owners', 'property_listings.landlord_id', '=', 'owners.id')
                ->selectRaw('property_listings.id as listing_id, property_listings.title, property_listings.barangay, owners.first_name, owners.last_name, SUM(property_listings.reservation_fee) as revenue')
                ->groupBy('property_listings.id', 'property_listings.title', 'property_listings.barangay', 'owners.first_name', 'owners.last_name')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'id'       => $r->listing_id,
                    'title'    => $r->title,
                    'barangay' => $r->barangay,
                    'owner'    => trim("{$r->first_name} {$r->last_name}"),
                    'revenue'  => (float) $r->revenue,
                ]);

            // ── Transaction volume ──
            $totalPaidTransactions = Reservation::where('payment_status', 'paid')->count();
            $transactionsThisMonth = Reservation::where('payment_status', 'paid')
                ->where('paid_at', '>=', $startOfMonth)->count();

            // ── Refunds ──
            $refundedReservations = Reservation::where('payment_status', 'refunded')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->get(['reservations.id', 'property_listings.reservation_fee']);
            $refundsCount  = $refundedReservations->count();
            $refundsAmount = (float) $refundedReservations->sum('reservation_fee');

            $refundFailedCount = Reservation::where('payment_status', 'refund_failed')->count();

            // ── Outstanding payments — approved reservations still waiting on payment ──
            $outstanding = Reservation::where('payment_status', 'pending')
                ->where('status', 'approved')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->get(['reservations.id', 'property_listings.reservation_fee']);
            $outstandingCount  = $outstanding->count();
            $outstandingAmount = (float) $outstanding->sum('reservation_fee');

            return response()->json([
                'success' => true,
                'data' => [
                    'revenue_by_month'      => $revenueByMonth,
                    'revenue_by_property'   => $revenueByProperty,
                    'total_transactions'    => $totalPaidTransactions,
                    'transactions_this_month' => $transactionsThisMonth,
                    'refunds' => [
                        'count'         => $refundsCount,
                        'amount'        => $refundsAmount,
                        'failed_count'  => $refundFailedCount,
                    ],
                    'outstanding' => [
                        'count'  => $outstandingCount,
                        'amount' => $outstandingAmount,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('platformRevenueReport error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: list all landlords with basic stats, for the "click into a landlord" list view
     */
    public function landlordsList()
    {
        try {
            $landlords = User::where('role', 'landlord')
                ->get(['id', 'first_name', 'last_name', 'email', 'profile_picture', 'is_active']);
 
            // Attach listing count per landlord without assuming a relationship exists
            $counts = PropertyListing::selectRaw('landlord_id, COUNT(*) as cnt')
                ->groupBy('landlord_id')
                ->pluck('cnt', 'landlord_id');
 
            $landlords->each(function ($l) use ($counts) {
                $l->listings_count = $counts[$l->id] ?? 0;
            });
 
            return response()->json(['success' => true, 'data' => $landlords]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ================================================
    // PRIVATE HELPERS
    // ================================================

    private function fillMissingMonths($data, Carbon $startMonth, $valueKey = 'count')
    {
        $map = [];
        foreach ($data as $row) {
            $map[$row->month] = $row->{$valueKey} ?? 0;
        }

        $result = collect();
        $cursor = $startMonth->copy();
        for ($i = 0; $i < 6; $i++) {
            $key = $cursor->format('Y-m');
            $result->push([
                'month' => $cursor->format('M Y'),
                'count' => (float) ($map[$key] ?? 0),
            ]);
            $cursor->addMonth();
        }

        return $result;
    }

    /**
     * Linear Regression forecast — predicts the next single data point.
     * y = mx + b, ordinary least squares. Deterministic, fully explainable.
     */
    private function linearRegressionForecast(array $values): float
    {
        $forecasts = $this->linearRegressionForecastMulti($values, 1);
        return $forecasts[0] ?? 0;
    }

    /**
     * Linear Regression forecast — predicts the next N data points forward.
     *
     * Fits a single line (y = mx + b) to the historical data, then projects
     * it forward for `periodsAhead` additional time steps. This is the same
     * deterministic least-squares method as the single-month version, just
     * evaluated at multiple future x values instead of one.
     */
    private function linearRegressionForecastMulti(array $values, int $periodsAhead = 1): array
    {
        return (new ForecastingService())->linearRegressionForecastMulti($values, $periodsAhead);
    }

    /**
     * Generate a plain-language insight about the trend using Gemini.
     * Falls back to a rule-based sentence if the AI call fails.
     */
    private function generateInsight(string $context, array $demandValues, float $demandForecast, array $reservationValues = []): string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-3.1-flash-lite');

        if (!$apiKey) {
            return $this->fallbackInsight($demandValues, $demandForecast);
        }

        try {
            $prompt = "You are analyzing {$context} for a housing platform called TierHome. "
                . "Monthly tenant search/interest activity for the last 6 months: [" . implode(', ', $demandValues) . "]. "
                . "Monthly actual reservations for the last 6 months: [" . implode(', ', $reservationValues) . "]. "
                . "The forecast for next month's search/interest activity is: {$demandForecast}. "
                . "Write exactly 2 short sentences summarizing the trend in plain, friendly language "
                . "suitable for a dashboard. Focus on whether tenant interest is growing, and whether that interest "
                . "is converting into actual reservations. Do not use technical jargon. Do not repeat the raw numbers verbatim.";

            $res = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => 150,
                    ],
                ]
            );

            if ($res->successful()) {
                $text = data_get($res->json(), 'candidates.0.content.parts.0.text');
                if ($text) return trim($text);
            }
        } catch (\Exception $e) {
            Log::warning('Gemini insight generation failed: ' . $e->getMessage());
        }

        return $this->fallbackInsight($demandValues, $demandForecast);
    }

    private function fallbackInsight(array $historicalValues, float $forecast): string
    {
        $recent = end($historicalValues) ?: 0;
        if ($forecast > $recent) {
            return "Tenant interest appears to be trending upward, with next month's search activity projected to be higher than the current level.";
        } elseif ($forecast < $recent) {
            return "Tenant interest appears to be trending downward, with next month's search activity projected to be lower than the current level.";
        }
        return "Tenant interest appears relatively stable, with next month's search activity projected to be similar to the current level.";
    }
}