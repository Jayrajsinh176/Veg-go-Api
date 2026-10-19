<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminActivityLog extends Model
{
    public const PANEL_ADMIN = 'admin';
    public const PANEL_SUPER_ADMIN = 'super_admin';

    /**
     * Set when any audit row is written; LogAdminActivity resets it per
     * request so it doesn't add a second row for a controller that
     * already logged its own change.
     */
    public static bool $recorded = false;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
        'panel',
        'action',
        'module',
        'target',
        'field',
        'old_value',
        'new_value',
        'change_summary',
        'reason',
        'details',
        'ip_address',
        'user_agent',
        'source',
        'method',
        'path',
        'session_id',
        'request_id',
        'risk_level',
        'review_status',
        'notes',
    ];

    protected $casts = [
        'notes' => 'array',
    ];

    protected static function booted(): void
    {
        // Controllers that call create() directly don't pass the panel
        static::creating(function (self $log) {
            $log->panel ??= self::panelFor($log->path ?: request()?->path());
        });

        static::created(fn () => self::$recorded = true);
    }

    public static function panelFor(?string $path): string
    {
        return str_starts_with(ltrim((string) $path, '/'), 'api/super-admin')
            ? self::PANEL_SUPER_ADMIN
            : self::PANEL_ADMIN;
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Restaurant names for the "#RES-{id}" targets in the given logs,
     * keyed by target, so the list can show a name instead of the id.
     */
    public static function restaurantTargetNames(iterable $logs): array
    {
        $ids = collect($logs)
            ->map(fn ($log) => preg_match('/^#RES-(\d+)$/', (string) $log->target, $match) ? (int) $match[1] : null)
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Restaurant::query()
            ->whereIn('id', $ids)
            ->whereNotNull('restaurant_name')
            ->pluck('restaurant_name', 'id')
            ->mapWithKeys(fn ($name, $id) => ['#RES-' . $id => $name])
            ->all();
    }

    /**
     * "#RES-{id}" targets of restaurants whose name matches the term,
     * so searching the logs by restaurant name still finds them.
     */
    public static function restaurantTargetsMatching(string $term): array
    {
        return Restaurant::query()
            ->where('restaurant_name', 'like', "%{$term}%")
            ->limit(200)
            ->pluck('id')
            ->map(fn ($id) => '#RES-' . $id)
            ->all();
    }

    /**
     * Write an audit record for the given admin and request.
     */
    public static function record(?Admin $admin, Request $request, array $attributes): self
    {
        return static::create(array_merge([
            'admin_id' => $admin?->id,
            'admin_name' => $admin?->name,
            'admin_email' => $admin?->email,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'risk_level' => 'Low',
            'review_status' => 'Needs Review',
        ], $attributes));
    }
}
