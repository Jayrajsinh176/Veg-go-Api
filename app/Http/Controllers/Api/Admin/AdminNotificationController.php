<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\AdminNotificationRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notifications page + header bell. Shared by the Admin panel
 * (/api/admin/notifications) and the Super Admin panel
 * (/api/super-admin/inbox). Every query goes through visibleTo(), so an
 * admin only ever sees types their role can view and has switched on.
 */
class AdminNotificationController extends Controller
{
    /**
     * Filters: tab (all|unread|read), type, search, page, per_page
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'type' => ['nullable', Rule::in(['all', ...AdminNotification::types()])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $admin = $this->admin($request);
        $type = $validated['type'] ?? 'all';

        $base = AdminNotification::query()
            ->visibleTo($admin)
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when(trim((string) ($validated['search'] ?? '')), fn ($q, $search) => $this->applySearch($q, $search));

        // Tab counts follow the type + search filters
        $counts = [
            'all' => (clone $base)->count(),
            'unread' => (clone $base)->unreadBy($admin)->count(),
        ];
        $counts['read'] = $counts['all'] - $counts['unread'];

        $paginator = (clone $base)
            ->when(($validated['tab'] ?? 'all') === 'unread', fn ($q) => $q->unreadBy($admin))
            ->when(($validated['tab'] ?? 'all') === 'read', fn ($q) => $q->readBy($admin))
            ->select('admin_notifications.*')
            ->withReadAt($admin)
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'message' => 'Notifications fetched successfully.',
            'data' => [
                'counts' => $counts,
                'types' => $this->typeOptions($admin),
                'notifications' => collect($paginator->items())->map(fn ($row) => $this->format($row))->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Header bell badge.
     */
    public function unreadCount(Request $request)
    {
        $admin = $this->admin($request);

        return response()->json([
            'success' => true,
            'data' => [
                'unread' => AdminNotification::query()->visibleTo($admin)->unreadBy($admin)->count(),
            ],
        ]);
    }

    public function markRead(Request $request, $id)
    {
        $admin = $this->admin($request);
        $notification = $this->findVisible($admin, (int) $id);

        AdminNotificationRead::query()->firstOrCreate(
            ['admin_notification_id' => $notification->id, 'admin_id' => $admin->id],
            ['read_at' => now()]
        );

        return $this->detailResponse($admin, $notification->id, 'Marked as read.');
    }

    public function markUnread(Request $request, $id)
    {
        $admin = $this->admin($request);
        $notification = $this->findVisible($admin, (int) $id);

        AdminNotificationRead::query()
            ->where('admin_notification_id', $notification->id)
            ->where('admin_id', $admin->id)
            ->delete();

        return $this->detailResponse($admin, $notification->id, 'Marked as unread.');
    }

    /**
     * Marks every visible unread notification as read (optionally one type).
     */
    public function markAllRead(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['all', ...AdminNotification::types()])],
        ]);

        $admin = $this->admin($request);
        $type = $validated['type'] ?? 'all';
        $now = now();
        $marked = 0;

        AdminNotification::query()
            ->visibleTo($admin)
            ->unreadBy($admin)
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->select('id')
            ->chunkById(500, function ($rows) use ($admin, $now, &$marked) {
                $marked += AdminNotificationRead::query()->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'admin_notification_id' => $row->id,
                        'admin_id' => $admin->id,
                        'read_at' => $now,
                    ])->all()
                );
            });

        return response()->json([
            'success' => true,
            'message' => $marked ? "{$marked} notifications marked as read." : 'Everything is already read.',
            'data' => ['marked' => $marked],
        ]);
    }

    public function settings(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->settingsPayload($this->admin($request)),
        ]);
    }

    /**
     * Body: { "types": { "support_request": false, ... } }
     */
    public function updateSettings(Request $request)
    {
        $rules = ['types' => ['required', 'array']];
        foreach (AdminNotification::types() as $type) {
            $rules["types.{$type}"] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);
        $admin = $this->admin($request);

        $current = $admin->notification_settings['types'] ?? [];
        foreach (AdminNotification::types() as $type) {
            if (array_key_exists($type, $validated['types'])) {
                $current[$type] = (bool) $validated['types'][$type];
            }
        }

        $admin->forceFill(['notification_settings' => ['types' => $current]])->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification settings saved.',
            'data' => $this->settingsPayload($admin),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function admin(Request $request): Admin
    {
        return $request->user();
    }

    private function findVisible(Admin $admin, int $id): AdminNotification
    {
        $notification = AdminNotification::query()->visibleTo($admin)->find($id);

        if (!$notification) {
            abort(response()->json(['success' => false, 'message' => 'Notification not found.'], 404));
        }

        return $notification;
    }

    private function detailResponse(Admin $admin, int $id, string $message)
    {
        $row = AdminNotification::query()
            ->select('admin_notifications.*')
            ->withReadAt($admin)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['notification' => $this->format($row)],
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%' . $search . '%';

        return $query->where(fn ($q) => $q
            ->where('title', 'like', $like)
            ->orWhere('message', 'like', $like)
            ->orWhere('record_ref', 'like', $like));
    }

    /**
     * Types this admin's role can see (settings switches apply on top).
     */
    private function allowedTypes(Admin $admin): array
    {
        if (!$admin->isRoleAdmin()) {
            return AdminNotification::types();
        }

        return array_values(array_filter(
            AdminNotification::types(),
            function ($type) use ($admin) {
                $module = AdminNotification::TYPES[$type]['module'];

                return $module === null || $admin->hasPermission($module, 'View');
            }
        ));
    }

    private function typeOptions(Admin $admin): array
    {
        $enabled = AdminNotification::enabledTypesFor($admin);

        return collect($this->allowedTypes($admin))
            ->filter(fn ($type) => in_array($type, $enabled, true))
            ->map(fn ($type) => ['key' => $type, 'label' => AdminNotification::TYPES[$type]['label']])
            ->values()
            ->all();
    }

    private function settingsPayload(Admin $admin): array
    {
        $enabled = AdminNotification::enabledTypesFor($admin);

        return [
            'types' => collect($this->allowedTypes($admin))
                ->map(fn ($type) => [
                    'key' => $type,
                    'label' => AdminNotification::TYPES[$type]['label'],
                    'enabled' => in_array($type, $enabled, true),
                ])
                ->values()
                ->all(),
        ];
    }

    private function format(AdminNotification $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'type_label' => AdminNotification::TYPES[$row->type]['label'] ?? $row->type,
            'title' => $row->title,
            'message' => $row->message,
            'record' => $row->record_type
                ? ['type' => $row->record_type, 'id' => $row->record_id, 'ref' => $row->record_ref]
                : null,
            'meta' => $row->meta,
            'is_read' => $row->read_at !== null,
            'read_at' => $row->read_at,
            'created_at' => $row->created_at,
        ];
    }
}
