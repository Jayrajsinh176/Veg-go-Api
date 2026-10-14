<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ChangeActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChangeActivityController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'admin' => ['nullable', 'string', 'max:191'],
            'module' => ['nullable', 'string', 'max:191'],
            'action' => ['nullable', 'string', 'max:191'],
            'date' => ['nullable', 'date'],
        ]);

        $events = ChangeActivityEvent::query()
            ->when($validated['search'] ?? null, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('target', 'like', "%{$term}%")
                        ->orWhere('action', 'like', "%{$term}%")
                        ->orWhere('module', 'like', "%{$term}%")
                        ->orWhere('admin_name', 'like', "%{$term}%");
                });
            })
            ->when($validated['admin'] ?? null, fn ($query, $admin) => $query->where('admin_name', $admin))
            ->when($validated['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($validated['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 5);

        $events->through(fn (ChangeActivityEvent $event) => $this->transform($event));

        return response()->json([
            'message' => 'Change activity fetched successfully.',
            'data' => $events,
            'filters' => [
                'admins' => ChangeActivityEvent::query()->whereNotNull('admin_name')->distinct()->orderBy('admin_name')->pluck('admin_name')->values(),
                'modules' => ChangeActivityEvent::query()->distinct()->orderBy('module')->pluck('module')->values(),
                'actions' => ChangeActivityEvent::query()->distinct()->orderBy('action')->pluck('action')->values(),
            ],
        ]);
    }

    private function transform(ChangeActivityEvent $event): array
    {
        $created = Carbon::parse($event->created_at);
        $admin = $event->admin_name ?: 'Unknown Admin';

        return [
            'id' => 'CHG-' . str_pad((string) $event->id, 6, '0', STR_PAD_LEFT),
            'admin' => $admin,
            'initials' => collect(explode(' ', $admin))
                ->filter()
                ->take(2)
                ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
                ->implode(''),
            'action' => $event->action,
            'actionType' => Str::slug($event->action),
            'module' => $event->module,
            'target' => $event->target ?: '—',
            'oldValue' => '—',
            'newValue' => '—',
            'details' => $event->details,
            'date' => $created->toDateString(),
            'displayDate' => $created->format('M j, Y'),
            'time' => $created->format('H:i'),
            'createdAt' => $created->toIso8601String(),
        ];
    }
}
