<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - VegGo</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc; color: #0f172a;
        }
        .box {
            max-width: 440px; width: 90%; margin: 20px; padding: 36px 28px;
            background: #ffffff; border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06); text-align: center;
        }
        .icon-circle {
            width: 64px; height: 64px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
        }
        .icon-circle.success { background: #ecfdf5; color: #059669; }
        .icon-circle.error { background: #fef2f2; color: #dc2626; }
        .icon-circle.info { background: #eff6ff; color: #2563eb; }
        .icon-circle svg { width: 32px; height: 32px; }
        h1 { font-size: 22px; margin: 0 0 10px; font-weight: 700; color: #1e293b; }
        p.msg { font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.5; }
        .meta {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 16px; margin-bottom: 24px; text-align: left; font-size: 14px;
        }
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .meta-row:last-child { margin-bottom: 0; }
        .meta-label { color: #64748b; }
        .meta-val { font-weight: 600; color: #1e293b; }
        .btn {
            display: inline-block; width: 100%; padding: 14px 20px; border-radius: 12px;
            background: #2563eb; color: #ffffff; font-size: 15px; font-weight: 600;
            text-decoration: none; border: none; cursor: pointer; box-sizing: border-box;
            transition: background 0.2s;
        }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="box">
        @if(($type ?? 'info') === 'success')
            <div class="icon-circle success">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
            </div>
        @else
            <div class="icon-circle error">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
            </div>
        @endif

        <h1>{{ $title }}</h1>
        <p class="msg">{{ $message }}</p>

        @if(!empty($ad))
            <div class="meta">
                <div class="meta-row">
                    <span class="meta-label">Campaign ID:</span>
                    <span class="meta-val">AD-{{ str_pad($ad->id, 5, '0', STR_PAD_LEFT) }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Restaurant:</span>
                    <span class="meta-val">{{ $ad->restaurant->restaurant_name ?? '—' }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Package:</span>
                    <span class="meta-val">{{ $ad->package->name ?? '—' }} ({{ $ad->duration_days }} days)</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Payment Status:</span>
                    <span class="meta-val" style="text-transform: capitalize;">{{ $ad->payment_status }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Campaign Status:</span>
                    <span class="meta-val" style="text-transform: capitalize;">{{ $ad->status }}</span>
                </div>
            </div>
        @endif
    </div>
</body>
</html>

