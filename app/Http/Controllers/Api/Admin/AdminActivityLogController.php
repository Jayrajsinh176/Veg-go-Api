<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminActivityLogController extends Controller
{
    private const REVIEW_STATUSES = ['Needs Review', 'Reviewed', 'Escalated'];

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

        $logs = AdminActivityLog::query()
            ->when($validated['search'] ?? null, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    foreach (['admin_name', 'admin_email', 'action', 'module', 'target', 'change_summary'] as $column) {
                        $inner->orWhere($column, 'like', "%{$term}%");
                    }
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

        return response()->json([
            'message' => 'Activity logs fetched successfully.',
            'data' => $logs->map(fn ($log) => $this->transform($log))->values(),
        ]);
    }

    /**
     * Update review status / action label and add an audit note
     */
    public function update(Request $request, $id)
    {
        $log = AdminActivityLog::find($id);

        if (!$log) {
            return response()->json([
                'message' => 'Activity log not found.',
            ], 404);
        }

        $validated = $request->validate([
            'review_status' => ['required', Rule::in(self::REVIEW_STATUSES)],
            'action' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $notes = $log->notes ?? [];

        if (filled($validated['note'] ?? null)) {
            $notes[] = [
                'id' => (int) (microtime(true) * 1000),
                'author' => $request->user()?->name ?? 'Admin',
                'text' => trim($validated['note']),
                'time' => now()->format('H:i'),
            ];
        }

        $log->update([
            'review_status' => $validated['review_status'],
            'action' => filled($validated['action'] ?? null) ? $validated['action'] : $log->action,
            'notes' => $notes,
        ]);

        return response()->json([
            'message' => 'Audit record updated successfully.',
            'data' => $this->transform($log->fresh()),
        ]);
    }

    private function transform(AdminActivityLog $log): array
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
            'target' => $log->target ?? '—',

            'change' => $log->change_summary ?? $log->action,
            'oldValue' => $log->old_value ?? '—',
            'newValue' => $log->new_value ?? '—',

            'date' => $created->toDateString(),
            'displayDate' => $displayDate,
            'time' => $created->format('H:i'),
            'createdAt' => $created->toIso8601String(),

            'reason' => $log->reason ?? 'No reason provided.',
            'details' => $log->details ?? $log->change_summary ?? $log->action,

            'ip' => $log->ip_address ?? '—',
            'device' => $this->device($log->user_agent),
            'location' => 'Admin Operations Console',
            'source' => $log->source ?? 'VegGo Admin Portal',

            'sessionId' => $log->session_id ?? '—',
            'requestId' => $log->request_id ?? '—',

            'riskLevel' => $log->risk_level,
            'reviewStatus' => $log->review_status,

            'notes' => $log->notes ?? [],
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
