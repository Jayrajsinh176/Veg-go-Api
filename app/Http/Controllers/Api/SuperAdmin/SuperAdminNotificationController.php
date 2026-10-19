<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\BroadcastNotification;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Restaurant;
use App\Models\RestaurantNotification;
use App\Models\Rider;
use App\Models\RiderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminNotificationController extends Controller
{
    /**
     * Display a listing of broadcast notifications, with metrics and pagination.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 5)));

        $query = BroadcastNotification::query()->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%")
                  ->orWhere('audience', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        // Calculate summary metrics across all broadcasts
        $allBroadcasts = BroadcastNotification::all();
        $totalSent = (int) $allBroadcasts->where('status', 'Delivered')->sum('total_recipients');
        $totalDelivered = (int) $allBroadcasts->where('status', 'Delivered')->sum('delivered_count');
        $totalFailed = (int) $allBroadcasts->sum('failed_count');
        $activeBroadcasts = (int) $allBroadcasts->where('status', 'Scheduled')->count();

        // Calculate delivered percentage
        $deliveryRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 100.0;

        $items = collect($paginator->items())->map(function ($item) {
            return $this->formatBroadcastRow($item);
        });

        return response()->json([
            'message' => 'Broadcast notifications fetched successfully.',
            'metrics' => [
                'total_sent' => $this->formatCount($totalSent),
                'total_sent_raw' => $totalSent,
                'delivered_rate' => $deliveryRate . '%',
                'active_broadcasts' => $activeBroadcasts,
                'failed_count' => $totalFailed,
                'total_count' => $allBroadcasts->count(),
            ],
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Store and optionally dispatch a newly created broadcast notification.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'segment' => ['required', 'string', 'max:150'],
            'channels' => ['nullable', 'array'],
            'action' => ['nullable', 'string', 'in:Send,Schedule,Draft'],
        ]);

        $action = $validated['action'] ?? 'Send';
        $segment = $validated['segment'];
        $channels = $validated['channels'] ?? [
            'push' => true,
            'inApp' => true,
        ];

        // Generate next NOTIF-XX code
        $nextId = (BroadcastNotification::max('id') ?? 0) + 1;
        $code = 'NOTIF-' . str_pad($nextId, 2, '0', STR_PAD_LEFT);

        // Count recipients based on segment
        $recipientsCount = $this->calculateRecipientsCount($segment);

        $isScheduled = ($action === 'Schedule');
        $isDraft = ($action === 'Draft');

        $status = $isDraft ? 'Draft' : ($isScheduled ? 'Scheduled' : 'Delivered');
        $deliveredCount = ($status === 'Delivered') ? $recipientsCount : 0;

        return DB::transaction(function () use (
            $code,
            $validated,
            $segment,
            $channels,
            $recipientsCount,
            $deliveredCount,
            $status,
            $request
        ) {
            $broadcast = BroadcastNotification::create([
                'code' => $code,
                'title' => $validated['title'],
                'message' => $validated['body'],
                'audience' => $segment,
                'channels' => $channels,
                'total_recipients' => $recipientsCount,
                'delivered_count' => $deliveredCount,
                'failed_count' => 0,
                'status' => $status,
                'sent_at' => $status === 'Delivered' ? now() : null,
                'created_by' => $request->user()?->id,
            ]);

            // If sent now and in-app / push is enabled, distribute into target user tables
            if ($status === 'Delivered') {
                $this->distributeInAppNotifications($segment, $validated['title'], $validated['body']);

                app(\App\Services\Notification\AdminNotificationService::class)
                    ->broadcastSent($validated['title'], $segment, $code);
            }

            return response()->json([
                'message' => $status === 'Delivered'
                    ? 'Broadcast sent to active users successfully!'
                    : 'Broadcast scheduled/saved successfully!',
                'data' => $this->formatBroadcastRow($broadcast),
            ], 201);
        });
    }

    /**
     * Export all broadcast notifications for CSV download.
     */
    public function export(Request $request)
    {
        $broadcasts = BroadcastNotification::latest('id')->get()->map(function ($item) {
            return [
                'ID' => $item->code ?? ('NOTIF-' . str_pad($item->id, 2, '0', STR_PAD_LEFT)),
                'Title' => $item->title,
                'Message' => $item->message,
                'Audience' => $item->audience,
                'Channel' => $this->formatChannelString($item->channels),
                'Total Recipients' => $item->total_recipients,
                'Delivered' => $item->delivered_count,
                'Failed' => $item->failed_count,
                'Status' => $item->status,
                'Sent Date' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : 'N/A',
            ];
        });

        return response()->json([
            'message' => 'Broadcast notifications exported successfully.',
            'data' => $broadcasts,
        ]);
    }

    /**
     * Determine recipient count based on selected segment.
     */
    private function calculateRecipientsCount(string $segment): int
    {
        $seg = strtolower($segment);

        if (str_contains($seg, 'customer')) {
            $count = Customer::count();
            return max($count, 1);
        }

        if (str_contains($seg, 'restaurant')) {
            $count = Restaurant::count();
            return max($count, 1);
        }

        if (str_contains($seg, 'delivery') || str_contains($seg, 'rider')) {
            $count = Rider::count();
            return max($count, 1);
        }

        // All users or custom
        $total = Customer::count() + Restaurant::count() + Rider::count();
        return max($total, 1);
    }

    /**
     * Distribute in-app notification records to the appropriate audience tables.
     */
    private function distributeInAppNotifications(string $segment, string $title, string $message): void
    {
        $seg = strtolower($segment);

        $sendToCustomers = str_contains($seg, 'customer') || str_contains($seg, 'all') || str_contains($seg, 'user');
        $sendToRestaurants = str_contains($seg, 'restaurant') || str_contains($seg, 'all') || str_contains($seg, 'user');
        $sendToRiders = str_contains($seg, 'delivery') || str_contains($seg, 'rider') || str_contains($seg, 'all') || str_contains($seg, 'user');

        if ($sendToCustomers) {
            Customer::select('id')->chunk(200, function ($customers) use ($title, $message) {
                $records = [];
                $now = now();
                foreach ($customers as $c) {
                    $records[] = [
                        'customer_id' => $c->id,
                        'title' => $title,
                        'message' => $message,
                        'type' => 'promotion',
                        'order_id' => null,
                        'is_read' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (!empty($records)) {
                    CustomerNotification::insert($records);
                }
            });
        }

        if ($sendToRestaurants) {
            Restaurant::select('id')->chunk(200, function ($restaurants) use ($title, $message) {
                $records = [];
                $now = now();
                foreach ($restaurants as $r) {
                    $records[] = [
                        'restaurant_id' => $r->id,
                        'title' => $title,
                        'message' => $message,
                        'type' => 'promotion',
                        'reference_type' => null,
                        'reference_id' => null,
                        'is_read' => false,
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (!empty($records)) {
                    RestaurantNotification::insert($records);
                }
            });
        }

        if ($sendToRiders) {
            Rider::select('id')->chunk(200, function ($riders) use ($title, $message) {
                $records = [];
                $now = now();
                foreach ($riders as $rider) {
                    $records[] = [
                        'rider_id' => $rider->id,
                        'title' => $title,
                        'message' => $message,
                        'type' => 'general',
                        'is_read' => false,
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (!empty($records)) {
                    RiderNotification::insert($records);
                }
            });
        }
    }

    /**
     * Format a broadcast model for frontend display.
     */
    private function formatBroadcastRow(BroadcastNotification $item): array
    {
        return [
            'id' => $item->code ?? ('NOTIF-' . str_pad($item->id, 2, '0', STR_PAD_LEFT)),
            'database_id' => $item->id,
            'title' => $item->title,
            'body' => $item->message,
            'audience' => $item->audience,
            'channel' => $this->formatChannelString($item->channels),
            'channels' => $item->channels ?? [],
            'date' => $item->created_at ? $item->created_at->format('M d, h:i A') : 'Just now',
            'delivery' => number_format($item->delivered_count),
            'status' => $item->status,
        ];
    }

    /**
     * Format channels object into readable text (e.g. "Push", "SMS", "Email & Push").
     */
    private function formatChannelString($channels): string
    {
        if (is_string($channels)) {
            $channels = json_decode($channels, true);
        }

        if (!is_array($channels)) {
            return 'Push';
        }

        $active = [];
        if (!empty($channels['push'])) $active[] = 'Push';
        if (!empty($channels['inApp'])) $active[] = 'In-App';

        if (empty($active)) {
            return 'Push & In-App';
        }

        return implode(' & ', $active);
    }

    /**
     * Format numbers into compact readable format (e.g. 124500 -> 124.5k).
     */
    private function formatCount(int $number): string
    {
        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        }
        if ($number >= 1000) {
            return round($number / 1000, 1) . 'k';
        }
        return (string) $number;
    }
}

