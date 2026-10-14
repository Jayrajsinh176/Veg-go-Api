<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminActivityLog extends Model
{
    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
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

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Write an audit record for the given admin and request.
     */
    public static function record(?Admin $admin, Request $request, array $attributes): self
    {
        $token = $admin?->currentAccessToken();

        return static::create(array_merge([
            'admin_id' => $admin?->id,
            'admin_name' => $admin?->name,
            'admin_email' => $admin?->email,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'session_id' => $token && isset($token->id) ? 'SES-VG-' . $token->id : null,
            'request_id' => 'REQ-' . strtoupper(Str::random(8)),
            'risk_level' => 'Low',
            'review_status' => 'Needs Review',
            'notes' => [[
                'id' => 1,
                'author' => 'System',
                'text' => 'Audit record automatically generated.',
                'time' => now()->format('H:i'),
            ]],
        ], $attributes));
    }
}
