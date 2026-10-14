@php
    $content = [
        'paid' => ['icon' => '✓', 'title' => 'Payment successful', 'text' => 'Your order has been placed.', 'color' => '#16a34a'],
        'failed' => ['icon' => '✕', 'title' => 'Payment failed', 'text' => 'No money was taken, or it will be refunded by your bank. You can retry the payment from your order.', 'color' => '#dc2626'],
        'pending' => ['icon' => '…', 'title' => 'Confirming payment', 'text' => 'We are confirming your payment with the bank. Please check your order in a moment.', 'color' => '#d97706'],
    ][$status];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content['title'] }} - VegGo</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f6f7f6; color: #1f2937; }
        .card { max-width: 360px; margin: 16px; padding: 32px 24px; background: #fff; border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,.06); text-align: center; }
        .icon { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; display: flex;
                align-items: center; justify-content: center; font-size: 32px; color: #fff; background: {{ $content['color'] }}; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { margin: 0; color: #6b7280; line-height: 1.5; }
        .order { margin-top: 16px; font-size: 14px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{{ $content['icon'] }}</div>
        <h1>{{ $content['title'] }}</h1>
        <p>{{ $content['text'] }}</p>
        @if ($orderId)
            <p class="order">Order #{{ $orderId }}</p>
        @endif
    </div>
</body>
</html>
