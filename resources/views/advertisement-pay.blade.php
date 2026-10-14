<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay for Advertisement - VegGo</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9; color: #0f172a;
        }
        .box {
            max-width: 480px; width: 92%; margin: 24px auto; padding: 32px 28px;
            background: #ffffff; border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center;
            box-sizing: border-box;
        }
        .header-logo {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 20px; font-weight: 800; color: #16a34a; margin-bottom: 12px;
        }
        .badge {
            display: inline-block; padding: 5px 12px; border-radius: 999px;
            background: #eff6ff; color: #2563eb; font-size: 12px; font-weight: 600; margin-bottom: 16px;
        }
        h1 { font-size: 20px; margin: 0 0 6px; font-weight: 700; color: #1e293b; }
        p.sub { font-size: 14px; color: #64748b; margin: 0 0 20px; line-height: 1.4; }
        .details-card {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px;
            padding: 16px 18px; margin-bottom: 20px; text-align: left; font-size: 13px;
        }
        .row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .row:last-child { margin-bottom: 0; }
        .row.total {
            font-size: 16px; font-weight: 700; color: #0f172a;
            border-top: 1px dashed #cbd5e1; padding-top: 12px; margin-top: 12px;
        }
        .total-amount { color: #16a34a; font-size: 18px; font-weight: 800; }
        .label { color: #64748b; }
        .value { font-weight: 600; color: #1e293b; text-align: right; }
        .banner-preview {
            margin-bottom: 16px; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0;
            max-height: 120px;
        }
        .banner-preview img { width: 100%; height: 120px; object-fit: cover; display: block; }
        .btn-pay {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 15px 20px; border-radius: 12px;
            background: #16a34a; color: #ffffff; font-size: 16px; font-weight: 700;
            border: none; cursor: pointer; transition: background 0.2s, transform 0.1s;
            box-sizing: border-box; text-decoration: none;
        }
        .btn-pay:hover { background: #15803d; }
        .btn-pay:active { transform: scale(0.99); }
        .trust-badges {
            margin-top: 18px; display: flex; align-items: center; justify-content: center; gap: 16px;
            font-size: 11px; color: #94a3b8; font-weight: 500;
        }
        .trust-badges span { display: inline-flex; align-items: center; gap: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="header-logo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            VegGo Partner
        </div>

        <div><span class="badge">Promotional Campaign</span></div>
        <h1>{{ $ad->package->name ?? 'Advertisement Campaign' }}</h1>
        <p class="sub">For <b>{{ $ad->restaurant->restaurant_name ?? 'Your Restaurant' }}</b></p>

        @if($ad->imageUrl())
            <div class="banner-preview">
                <img src="{{ $ad->imageUrl() }}" alt="Advertisement Preview" />
            </div>
        @endif

        <div class="details-card">
            <div class="row">
                <span class="label">Campaign Reference:</span>
                <span class="value">AD-{{ str_pad($ad->id, 5, '0', STR_PAD_LEFT) }}</span>
            </div>
            <div class="row">
                <span class="label">Duration:</span>
                <span class="value">{{ $ad->duration_days }} Days</span>
            </div>
            @if($ad->target_type === 'food_item' && $ad->foodItem)
                <div class="row">
                    <span class="label">Promoted Item:</span>
                    <span class="value">{{ $ad->foodItem->name }}</span>
                </div>
            @endif
            <div class="row">
                <span class="label">Base Amount:</span>
                <span class="value">₹{{ number_format($ad->amount, 2) }}</span>
            </div>
            <div class="row">
                <span class="label">GST ({{ (float)$ad->tax_percentage }}%):</span>
                <span class="value">₹{{ number_format($ad->tax_amount, 2) }}</span>
            </div>
            <div class="row total">
                <span>Total Amount:</span>
                <span class="total-amount">₹{{ number_format($ad->total_amount, 2) }}</span>
            </div>
        </div>

        <form id="payuForm" method="POST" action="{{ $action }}">
            @foreach($params as $key => $val)
                <input type="hidden" name="{{ $key }}" value="{{ $val }}" />
            @endforeach

            <button type="submit" class="btn-pay" id="payBtn">
                Proceed to Pay ₹{{ number_format($ad->total_amount, 2) }}
            </button>
        </form>

        <div class="trust-badges">
            <span>🔒 Secured by PayU</span>
            <span>⚡ Instant Activation</span>
            <span>📱 UPI / Cards / NetBanking</span>
        </div>
    </div>
</body>
</html>

