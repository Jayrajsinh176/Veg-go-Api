<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin panel notification. Written only by AdminNotificationService;
 * read state lives in admin_notification_reads (one row per admin).
 */
class AdminNotification extends Model
{
    public const TYPE_RESTAURANT_APPLICATION = 'restaurant_application';
    public const TYPE_RIDER_APPLICATION = 'rider_application';
    public const TYPE_SUPPORT_REQUEST = 'support_request';
    public const TYPE_ORDER_ISSUE = 'order_issue';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_PAYMENT_ISSUE = 'payment_issue';
    public const TYPE_SYSTEM = 'system';

    /*
     * Label shown in the panel and the permission module a role admin
     * needs (config/admin_permissions.php). NULL module = every admin.
     */
    public const TYPES = [
        self::TYPE_RESTAURANT_APPLICATION => ['label' => 'Restaurant Application', 'module' => 'restaurantApplications'],
        self::TYPE_RIDER_APPLICATION => ['label' => 'Delivery Application', 'module' => 'deliveryApplications'],
        self::TYPE_SUPPORT_REQUEST => ['label' => 'Support Request', 'module' => 'support'],
        self::TYPE_ORDER_ISSUE => ['label' => 'Order Issue', 'module' => 'support'],
        self::TYPE_CANCELLATION => ['label' => 'Cancellation Request', 'module' => 'orders'],
        self::TYPE_PAYMENT_ISSUE => ['label' => 'Payment Issue', 'module' => 'payments'],
        self::TYPE_SYSTEM => ['label' => 'System Update', 'module' => null],
    ];

    protected $fillable = [
        'type',
        'title',
        'message',
        'module',
        'record_type',
        'record_id',
        'record_ref',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'record_id' => 'integer',
    ];

    public function reads()
    {
        return $this->hasMany(AdminNotificationRead::class, 'admin_notification_id');
    }

    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Types the admin has switched on (all by default).
     */
    public static function enabledTypesFor(Admin $admin): array
    {
        $settings = $admin->notification_settings['types'] ?? [];

        return array_values(array_filter(
            self::types(),
            fn ($type) => ($settings[$type] ?? true) !== false
        ));
    }

    /**
     * Notifications this admin may see: role admins only get modules
     * their role can View; plain admins and super admins get everything.
     * Types switched off in the admin's settings are left out.
     */
    public function scopeVisibleTo(Builder $query, Admin $admin): Builder
    {
        $query->whereIn('type', self::enabledTypesFor($admin));

        if ($admin->isRoleAdmin()) {
            $modules = collect($admin->permissionMap())
                ->filter(fn ($actions) => in_array('View', (array) $actions, true))
                ->keys()
                ->all();

            $query->where(fn ($q) => $q->whereNull('module')->orWhereIn('module', $modules));
        }

        return $query;
    }

    public function scopeUnreadBy(Builder $query, Admin $admin): Builder
    {
        return $query->whereDoesntHave('reads', fn ($q) => $q->where('admin_id', $admin->id));
    }

    public function scopeReadBy(Builder $query, Admin $admin): Builder
    {
        return $query->whereHas('reads', fn ($q) => $q->where('admin_id', $admin->id));
    }

    public function scopeWithReadAt(Builder $query, Admin $admin): Builder
    {
        return $query->addSelect([
            'read_at' => AdminNotificationRead::query()
                ->select('read_at')
                ->whereColumn('admin_notification_id', 'admin_notifications.id')
                ->where('admin_id', $admin->id)
                ->limit(1),
        ]);
    }
}
