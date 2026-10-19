<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdminActivityLogController extends Controller
{
    /**
     * List activity logs (newest first)
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'admin_id' => ['nullable', 'integer'],
            'restaurant_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        // The admin panel only sees admin panel actions; super admin
        // actions are listed on the super admin Activity Logs page
        $logs = AdminActivityLog::query()
            ->where('panel', AdminActivityLog::PANEL_ADMIN)
            ->when($validated['search'] ?? null, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    foreach (['admin_name', 'admin_email', 'action', 'module', 'target', 'change_summary'] as $column) {
                        $inner->orWhere($column, 'like', "%{$term}%");
                    }

                    $inner->orWhereIn('target', AdminActivityLog::restaurantTargetsMatching($term));
                });
            })
            ->when($validated['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($validated['admin_id'] ?? null, fn ($query, $id) => $query->where('admin_id', $id))
            // Logs aimed at the restaurant itself, plus its menu changes
            // (those target the menu item but sit under its URL)
            ->when($validated['restaurant_id'] ?? null, function ($query, $id) {
                $query->where(function ($inner) use ($id) {
                    $inner->where('target', "#RES-{$id}")
                        ->orWhere('path', "/api/admin/restaurants/{$id}")
                        ->orWhere('path', 'like', "/api/admin/restaurants/{$id}/%");
                });
            })
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->limit($validated['limit'] ?? 500)
            ->get();

        $restaurantNames = AdminActivityLog::restaurantTargetNames($logs);

        return response()->json([
            'message' => 'Activity logs fetched successfully.',
            'data' => $logs->map(fn ($log) => $this->transform($log, $restaurantNames))->values(),
        ]);
    }

    private function transform(AdminActivityLog $log, array $restaurantNames = []): array
    {
        $created = Carbon::parse($log->created_at);
        $name = $log->admin_name ?: 'Unknown Admin';

        $displayDate = match (true) {
            $created->isToday() => 'Today',
            $created->isYesterday() => 'Yesterday',
            default => $created->format('M j, Y'),
        };

        return [
            'id' => $log->id,
            'adminId' => $log->admin_id,
            'initials' => collect(explode(' ', $name))
                ->filter()
                ->take(2)
                ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
                ->implode(''),
            'admin' => $name,
            'email' => $log->admin_email ?? '',

            'action' => $log->action,
            'module' => $log->module,
            'target' => $restaurantNames[$log->target] ?? ($log->target ?? '—'),

            'change' => $log->change_summary ?? $log->action,
            'oldValue' => $log->old_value ?? '—',
            'newValue' => $log->new_value ?? '—',

            'date' => $created->toDateString(),
            'displayDate' => $displayDate,
            'time' => $created->format('H:i'),
            'createdAt' => $created->toIso8601String(),

            // Only set for actions where a reason is typed in (reject, block…)
            'reason' => $log->reason,
            'details' => $log->details ?? $log->change_summary ?? $log->action,

            'device' => $this->device($log->user_agent),
            'source' => $log->source ?? 'VegGo Admin Portal',
        ];
    }

    private function device(?string $agent): string
    {
        if (!$agent) {
            return 'Unknown';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return "{$browser} / {$os}";
    }
}
