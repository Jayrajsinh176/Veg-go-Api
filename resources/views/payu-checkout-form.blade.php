<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - VegGo PayU Checkout</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #f1f5f9; color: #0f172a;
        }
        .box {
            max-width: 440px; width: 90%; margin: 20px; padding: 32px 28px;
            background: #ffffff; border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center;
        }
        .badge {
            display: inline-block; padding: 6px 14px; border-radius: 999px;
            background: #ecfdf5; color: #059669; font-size: 13px; font-weight: 600; margin-bottom: 16px;
        }
        h1 { font-size: 22px; margin: 0 0 8px; font-weight: 700; color: #1e293b; }
        p.sub { font-size: 15px; color: #64748b; margin: 0 0 24px; line-height: 1.5; }
        .details {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 16px; margin-bottom: 24px; text-align: left; font-size: 14px;
        }
        .row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .row:last-child { margin-bottom: 0; font-weight: 700; color: #0f172a; border-top: 1px dashed #cbd5e1; padding-top: 8px; }
        .label { color: #64748b; }
        .btn {
            display: block; width: 100%; padding: 14px 20px; border-radius: 12px;
            background: #16a34a; color: #ffffff; font-size: 16px; font-weight: 600;
            border: none; cursor: pointer; transition: background 0.2s; box-sizing: border-box;
        }
        .btn:hover { background: #15803d; }
        .hint { font-size: 12px; color: #94a3b8; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="badge">PayU Sandbox Test</div>
        <h1>{{ $title }}</h1>
        <p class="sub">{{ $subtitle }}</p>

        <div class="details">
            <div class="row">
                <span class="label">Transaction ID (txnid):</span>
                <span>{{ $params['txnid'] }}</span>
            </div>
            <div class="row">
                <span class="label">Customer / Owner:</span>
                <span>{{ $params['firstname'] }}</span>
            </div>
            <div class="row">
                <span class="label">Total Amount:</span>
                <span>₹{{ $params['amount'] }}</span>
            </div>
        </div>

        <form id="payuForm" method="POST" action="{{ $action }}">
            @foreach($params as $key => $val)
                <input type="hidden" name="{{ $key }}" value="{{ $val }}" />
            @endforeach

            <button type="submit" class="btn" id="payBtn">Proceed to PayU Payment</button>
        </form>

        <p class="hint">Will redirect to PayU Hosted Checkout. Select <b>UPI / Card</b> and click <b>Success</b> to complete.</p>
    </div>
</body>
</html>

