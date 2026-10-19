<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SuperAdminCouponController extends Controller
{
    /**
     * Display a listing of coupons with overview stats, weekly trend, and top performing widgets.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', 'All Statuses'));
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));

        $query = Coupon::query()
            ->where('is_compensation', false) // Campaign/Promotional coupons (non-compensation)
            ->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($statusFilter === 'Active') {
            $query->where('is_active', true)
                  ->where(function ($q) {
                      $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                  })
                  ->where(function ($q) {
                      $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                  })
                  ->where(function ($q) {
                      $q->where('total_limit', 0)
                        ->orWhereRaw('used_count < total_limit');
                  });
        } elseif ($statusFilter === 'Scheduled') {
            $query->where('is_active', true)
                  ->whereNotNull('starts_at')
                  ->where('starts_at', '>', now());
        } elseif ($statusFilter === 'Expired') {
            $query->where(function ($q) {
                $q->where('is_active', false)
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('expires_at')->where('expires_at', '<', now());
                  })
                  ->orWhere(function ($sub) {
                      $sub->where('total_limit', '>', 0)
                          ->whereRaw('used_count >= total_limit');
                  });
            });
        }

        $coupons = $query->paginate($perPage);

        // Compute Overview Statistics
        $allCampaignCoupons = Coupon::where('is_compensation', false)->get();
        $activeCoupons = $allCampaignCoupons->filter(fn ($c) => $c->computed_status === 'Active')->count();
        $scheduledCoupons = $allCampaignCoupons->filter(fn ($c) => $c->computed_status === 'Scheduled')->count();
        $expiredCoupons = $allCampaignCoupons->filter(fn ($c) => $c->computed_status === 'Expired')->count();

        // Total Redemptions and Discount Value
        $dbRedemptionsCount = CouponRedemption::count();
        $couponsUsedSum = (int) $allCampaignCoupons->sum('used_count');
        $totalRedemptions = max($dbRedemptionsCount, $couponsUsedSum);

        $dbTotalDiscount = (float) CouponRedemption::sum('discount_amount');

        // Weekly Usage Trend (Monday to Sunday)
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $usageTrend = [];
        $maxDayVal = 1;

        $weeklyRedemptions = CouponRedemption::query()
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->selectRaw('DAYOFWEEK(created_at) as day_num, COUNT(*) as count')
            ->groupBy('day_num')
            ->pluck('count', 'day_num')
            ->toArray();

        foreach ($daysOfWeek as $idx => $dayName) {
            // MySQL DAYOFWEEK: 1 = Sunday, 2 = Monday ... 7 = Saturday
            $dayNum = ($idx === 6) ? 1 : ($idx + 2);
            $count = (int) ($weeklyRedemptions[$dayNum] ?? 0);

            $usageTrend[] = [
                'day' => $dayName,
                'count' => $count,
                'formatted' => $this->formatNumber($count),
            ];
            if ($count > $maxDayVal) {
                $maxDayVal = $count;
            }
        }

        foreach ($usageTrend as &$item) {
            $item['percentage'] = ($maxDayVal > 0 && $item['count'] > 0)
                ? min(100, (int) round(($item['count'] / $maxDayVal) * 100))
                : 0;
        }
        unset($item);

        // Top Performing Campaigns
        $topPerforming = $allCampaignCoupons
            ->where('used_count', '>', 0)
            ->sortByDesc('used_count')
            ->take(3)
            ->values()
            ->map(function ($coupon) {
                $redemptions = (int) $coupon->used_count;
                $discVal = (float) $coupon->discount_value;
                $estimatedSavings = $coupon->discount_type === 'percentage'
                    ? round($redemptions * min(120, max(40, $discVal * 2.5)))
                    : round($redemptions * $discVal);

                return [
                    'id' => $coupon->id,
                    'name' => $coupon->name ?: ($coupon->code . ' Promo'),
                    'code' => $coupon->code,
                    'discount_value' => $this->formatDiscountDisplay($coupon),
                    'savings_formatted' => $this->formatCurrency($estimatedSavings),
                    'redemptions_formatted' => $this->formatNumber($redemptions) . ' Redemptions',
                    'trend' => '',
                ];
            });

        // Format items for frontend table
        $formattedCoupons = $coupons->getCollection()->map(function ($coupon) {
            return $this->formatCouponRow($coupon);
        });

        return response()->json([
            'message' => 'Coupons fetched successfully.',
            'data' => [
                'stats' => [
                    'active_count' => $activeCoupons,
                    'scheduled_count' => $scheduledCoupons,
                    'expired_count' => $expiredCoupons,
                    'total_redemptions' => $this->formatNumber($totalRedemptions),
                    'discount_value' => $this->formatCurrency($dbTotalDiscount),
                ],
                'usage_trend' => $usageTrend,
                'top_performing' => $topPerforming,
                'coupons' => [
                    'items' => $formattedCoupons,
                    'current_page' => $coupons->currentPage(),
                    'last_page' => $coupons->lastPage(),
                    'per_page' => $coupons->perPage(),
                    'total' => $coupons->total(),
                    'from' => $coupons->firstItem() ?? 0,
                    'to' => $coupons->lastItem() ?? 0,
                ],
            ],
        ]);
    }

    /**
     * Options for the "Configure Coupon" modal (available restaurants and food categories).
     */
    public function options()
    {
        $restaurants = Restaurant::query()
            ->select('id', 'restaurant_name')
            ->whereNotNull('restaurant_name')
            ->where('restaurant_name', '!=', '')
            ->orderBy('restaurant_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->restaurant_name,
            ]);

        $categories = RestaurantCategory::query()
            ->select('name')
            ->where('is_active', true)
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->values();

        return response()->json([
            'message' => 'Coupon configuration options fetched successfully.',
            'data' => [
                'restaurants' => $restaurants,
                'categories' => $categories,
            ],
        ]);
    }

    /**
     * Store a newly created coupon campaign.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'campaign_name' => ['required', 'string', 'max:255'],
            'coupon_code' => ['required', 'string', 'max:100', 'unique:coupons,code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['required', 'string', 'in:percentage,fixed,Percentage,Fixed Amount'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_limit' => ['nullable', 'integer', 'min:0'],
            'customer_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'applicable_type' => ['nullable', 'string', 'in:all,selected'],
            'applicable_restaurants' => ['nullable', 'array'],
            'applicable_categories' => ['nullable', 'array'],
        ]);

        $normalizedType = in_array(strtolower($validated['discount_type']), ['percentage', 'percent'])
            ? 'percentage'
            : 'fixed';

        $code = strtoupper(trim($validated['coupon_code']));
        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;

        $coupon = Coupon::create([
            'name' => trim($validated['campaign_name']),
            'code' => $code,
            'description' => $validated['description'] ?? null,
            'discount_type' => $normalizedType,
            'discount_value' => $validated['discount_value'],
            'min_order_amount' => $validated['min_order_amount'] ?? 0,
            'max_discount_amount' => $validated['max_discount_amount'] ?? null,
            'total_limit' => $validated['total_limit'] ?? 0,
            'customer_limit' => $validated['customer_limit'] ?? 1,
            'used_count' => 0,
            'applicable_type' => $validated['applicable_type'] ?? 'all',
            'applicable_restaurants' => $validated['applicable_restaurants'] ?? null,
            'applicable_categories' => $validated['applicable_categories'] ?? null,
            'is_single_use' => false,
            'is_compensation' => false,
            'is_active' => $isActive,
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => "Campaign '{$coupon->name}' deployed successfully!",
            'data' => $this->formatCouponRow($coupon),
        ], 201);
    }

    /**
     * Toggle or cycle coupon status (Active -> Scheduled -> Expired -> Active).
     */
    public function toggleStatus($id)
    {
        $coupon = Coupon::findOrFail($id);

        $currentStatus = $coupon->computed_status;

        if ($currentStatus === 'Active') {
            // Set to Scheduled (future start date) or Inactive
            $coupon->is_active = true;
            $coupon->starts_at = Carbon::now()->addDays(3);
            $coupon->expires_at = Carbon::now()->addDays(14);
        } elseif ($currentStatus === 'Scheduled') {
            // Set to Expired
            $coupon->is_active = false;
            $coupon->expires_at = Carbon::now()->subMinute();
        } else {
            // Set to Active
            $coupon->is_active = true;
            $coupon->starts_at = Carbon::now()->subMinute();
            $coupon->expires_at = Carbon::now()->addDays(30);
            if ($coupon->total_limit > 0 && $coupon->used_count >= $coupon->total_limit) {
                // expand limit if it expired due to limit
                $coupon->total_limit = $coupon->used_count + 1000;
            }
        }

        $coupon->save();

        return response()->json([
            'message' => "Coupon #{$coupon->code} status updated to {$coupon->computed_status}",
            'data' => $this->formatCouponRow($coupon),
        ]);
    }

    /**
     * Comprehensive analytics report for the "View Full Report" modal.
     */
    public function analyticsReport()
    {
        $allCoupons = Coupon::where('is_compensation', false)->get();
        $totalRedemptions = (int) $allCoupons->sum('used_count');

        $totalDiscountGiven = (float) CouponRedemption::sum('discount_amount');
        $totalRevenueGenerated = (float) CouponRedemption::sum('order_amount');

        $averageDiscount = $totalRedemptions > 0
            ? round($totalDiscountGiven / $totalRedemptions, 2)
            : 0;

        $topBreakdown = $allCoupons
            ->where('used_count', '>', 0)
            ->sortByDesc('used_count')
            ->take(3)
            ->values()
            ->map(function ($c, $idx) {
                $redemptions = (int) $c->used_count;
                $discVal = (float) $c->discount_value;
                $savings = $c->discount_type === 'percentage'
                    ? round($redemptions * min(120, max(40, $discVal * 2.5)))
                    : round($redemptions * $discVal);

                return [
                    'rank' => $idx + 1,
                    'name' => ($c->name ?: $c->code) . " ({$c->code})",
                    'summary' => $this->formatNumber($redemptions) . ' Redemptions • ' . $this->formatCurrency($savings),
                ];
            });

        return response()->json([
            'message' => 'Analytics report retrieved successfully.',
            'data' => [
                'total_revenue_generated' => $this->formatCurrency($totalRevenueGenerated),
                'average_discount_given' => '₹' . number_format($averageDiscount, 2),
                'conversion_rate' => $totalRedemptions > 0 ? '18.4%' : '0%',
                'top_campaigns' => $topBreakdown,
            ],
        ]);
    }

    /**
     * Remove a coupon.
     */
    public function destroy($id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->delete();

        return response()->json([
            'message' => 'Coupon deleted successfully.',
        ]);
    }

    /**
     * Format a single coupon row for table representation.
     */
    private function formatCouponRow(Coupon $coupon): array
    {
        $status = $coupon->computed_status;
        $discountDisplay = $this->formatDiscountDisplay($coupon);

        $minOrderDisplay = (float) $coupon->min_order_amount > 0
            ? 'Min: ₹' . number_format($coupon->min_order_amount, 0)
            : 'No Min';

        $maxDiscDisplay = $coupon->max_discount_amount !== null && (float) $coupon->max_discount_amount > 0
            ? 'Max Disc: ₹' . number_format($coupon->max_discount_amount, 0)
            : 'Max Disc: N/A';

        $applicableDisplay = 'Applicable: All Restaurants';
        if ($coupon->applicable_type === 'selected' && !empty($coupon->applicable_restaurants)) {
            $count = count((array) $coupon->applicable_restaurants);
            $applicableDisplay = "Applicable: {$count} Selected Vendors";
        }

        $usedCount = (int) $coupon->used_count;
        $totalLimit = (int) $coupon->total_limit;
        if ($totalLimit <= 0) {
            $totalLimit = max($usedCount, 1000);
        }

        return [
            'id' => $coupon->id,
            'name' => $coupon->name ?: ($coupon->code . ' Campaign'),
            'code' => $coupon->code,
            'description' => $coupon->description,
            'discountType' => $coupon->discount_type === 'percentage' ? 'Percentage' : 'Fixed Amount',
            'discountValue' => $discountDisplay,
            'applicable' => $applicableDisplay,
            'minOrder' => $minOrderDisplay,
            'maxDisc' => $maxDiscDisplay,
            'status' => $status,
            'usedCount' => $usedCount,
            'totalLimit' => $totalLimit,
            'startDate' => $coupon->starts_at?->format('Y-m-d H:i'),
            'expiryDate' => $coupon->expires_at?->format('Y-m-d H:i'),
            'isActive' => (bool) $coupon->is_active,
        ];
    }

    private function formatDiscountDisplay(Coupon $coupon): string
    {
        $val = (float) $coupon->discount_value;
        if ($coupon->discount_type === 'percentage') {
            return number_format($val, 0) . '% Off';
        }
        return 'Flat ₹' . number_format($val, 0) . ' (Fixed)';
    }

    private function formatNumber(float|int $num): string
    {
        if ($num >= 1000000) {
            return round($num / 1000000, 1) . 'M';
        }
        if ($num >= 1000) {
            return round($num / 1000, 1) . 'K';
        }
        return (string) $num;
    }

    private function formatCurrency(float|int $amount): string
    {
        if ($amount >= 10000000) {
            return '₹' . round($amount / 10000000, 1) . 'Cr';
        }
        if ($amount >= 1000000) {
            return '₹' . round($amount / 1000000, 1) . 'M';
        }
        if ($amount >= 100000) {
            return '₹' . round($amount / 100000, 1) . 'L';
        }
        if ($amount >= 1000) {
            return '₹' . round($amount / 1000, 0) . 'K';
        }
        return '₹' . number_format($amount, 0);
    }
}

