<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super admin Activity & Audit Logs: a read-only list of every change
 * made from the admin panel and the super admin panel.
 */
class SuperAdminActivityLogController extends Controller
{
    private const PANEL_LABELS = [
        AdminActivityLog::PANEL_ADMIN => 'Admin Panel',
        AdminActivityLog::PANEL_SUPER_ADMIN => 'Super Admin Panel',
    ];

    /**
     * Action badge colour, by keyword in the action name
     */
    private const ACTION_TYPES = [
        // Before "logo" so "Logout" isn't read as a settings change
        'login' => 'sync',
        'logout' => 'sync',
        'commission' => 'commission',
        'block' => 'block',
        'suspend' => 'block',
        'reject' => 'block',
        'delete' => 'block',
        'refund' => 'refund',
        'approv' => 'approval',
        'verif' => 'approval',
        'permission' => 'permission',
        'role' => 'permission',
        'setting' => 'settings',
        'logo' => 'settings',
        'settle' => 'settlement',
        'payout' => 'settlement',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'panel' => ['nullable', Rule::in(array_keys(self::PANEL_LABELS))],
            'admin' => ['nullable', 'string', 'max:191'],
            'module' => ['nullable', 'string', 'max:191'],
            'action' => ['nullable', 'string', 'max:191'],
            'date' => ['nullable', 'date'],
        ]);

        $logs = AdminActivityLog::query()
            ->when($validated['search'] ?? null, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    foreach (['target', 'action', 'module', 'admin_name', 'admin_email', 'change_summary'] as $column) {
                        $inner->orWhere($column, 'like', "%{$term}%");
                    }

                    $inner->orWhereIn('target', AdminActivityLog::restaurantTargetsMatching($term));
                });
            })
            ->when($validated['panel'] ?? null, fn ($query, $panel) => $query->where('panel', $panel))
            ->when($validated['admin'] ?? null, fn ($query, $admin) => $query->where('admin_name', $admin))
            ->when($validated['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($validated['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 5);

        $restaurantNames = AdminActivityLog::restaurantTargetNames($logs->items());

        $logs->through(fn (AdminActivityLog $log) => $this->transform($log, $restaurantNames));

        $distinct = fn (string $column) => AdminActivityLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->values();

        return response()->json([
            'message' => 'Activity logs fetched successfully.',
            'data' => $logs,
            'filters' => [
                'panels' => collect(self::PANEL_LABELS)
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'admins' => $distinct('admin_name'),
                'modules' => $distinct('module'),
                'actions' => $distinct('action'),
            ],
        ]);
    }

    private function transform(AdminActivityLog $log, array $restaurantNames = []): array
    {
        $created = Carbon::parse($log->created_at);
        $admin = $log->admin_name ?: 'Unknown Admin';
        [$old, $new] = $this->values($log);

        return [
            'id' => 'LOG-' . str_pad((string) $log->id, 6, '0', STR_PAD_LEFT),
            'admin' => $admin,
            'email' => $log->admin_email ?? '',
            'initials' => collect(explode(' ', $admin))
                ->filter()
                ->take(2)
                ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
                ->implode(''),
            'panel' => $log->panel,
            'panelLabel' => self::PANEL_LABELS[$log->panel] ?? 'Admin Panel',
            'action' => $log->action,
            'actionType' => $this->actionType($log->action),
            'module' => $log->module,
            'target' => $restaurantNames[$log->target] ?? ($log->target ?: '—'),
            'oldValue' => $old,
            'newValue' => $new,
            'summary' => $log->change_summary ?: $log->action,
            'details' => $log->details ?: $log->change_summary ?: $log->action,
            'reason' => $log->reason,
            'source' => $log->source,
            'ipAddress' => $log->ip_address,
            'userAgent' => $log->user_agent,
            'date' => $created->toDateString(),
            'displayDate' => $created->format('M j, Y'),
            'time' => $created->format('H:i'),
            'createdAt' => $created->toIso8601String(),
        ];
    }

    /**
     * Old / new values; rows written directly by controllers only have
     * a "Field: Old → New" summary, so read the values from that.
     */
    private function values(AdminActivityLog $log): array
    {
        if ($log->old_value !== null || $log->new_value !== null) {
            return [$log->old_value ?? '—', $log->new_value ?? '—'];
        }

        if ($log->change_summary && preg_match('/^[^:]{1,40}:\s*(.+?)\s*→\s*(.+)$/u', $log->change_summary, $match)) {
            return [$match[1], $match[2]];
        }

        return ['—', '—'];
    }

    private function actionType(string $action): string
    {
        $action = Str::lower($action);

        foreach (self::ACTION_TYPES as $keyword => $type) {
            if (str_contains($action, $keyword)) {
                return $type;
            }
        }

        return 'default';
    }
}
