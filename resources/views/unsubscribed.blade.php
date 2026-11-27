<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Unsubscribed · toady</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; padding: 24px;
            background: #04070b;
            background-image: radial-gradient(120% 80% at 50% -10%, rgba(28,240,160,.10) 0%, transparent 55%);
            font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            display: flex; align-items: center; justify-content: center;
        }
        .wrap { width: 100%; max-width: 440px; }
        .brand {
            text-align: center; margin-bottom: 18px;
            font-family: ui-monospace, 'SFMono-Regular', 'Courier New', monospace;
            color: #1cf0a0; font-size: 19px; letter-spacing: .22em;
            text-shadow: 0 0 12px rgba(28,240,160,.55);
        }
        .card {
            background: #0a121a; border: 1px solid #15323a; border-radius: 14px;
            padding: 34px 28px; text-align: center;
            box-shadow: inset 0 0 0 1px rgba(28,240,160,.06), 0 0 44px -14px rgba(28,240,160,.4);
        }
        .check {
            width: 58px; height: 58px; margin: 0 auto 20px; border-radius: 50%;
            background: rgba(28,240,160,.1); border: 1px solid rgba(28,240,160,.45);
            display: flex; align-items: center; justify-content: center;
            color: #1cf0a0; font-size: 30px; line-height: 1;
            box-shadow: 0 0 22px -6px rgba(28,240,160,.5);
        }
        h1 { margin: 0 0 12px; font-size: 22px; font-weight: 600; color: #d6f5e8; }
        .lead { margin: 0 0 8px; font-size: 15px; line-height: 1.6; color: #7fa6a0; }
        .lead b { color: #d6f5e8; font-weight: 600; }
        .note { margin: 0 0 24px; font-size: 13.5px; line-height: 1.6; color: #4f7a78; }
        .btn {
            display: inline-block; background: #1cf0a0; color: #02110b;
            font-family: ui-monospace, monospace; font-weight: 600; font-size: 14px;
            text-decoration: none; padding: 11px 24px; border-radius: 8px;
            box-shadow: 0 0 20px -6px rgba(28,240,160,.6);
        }
        .tag {
            text-align: center; margin: 18px 0 0; font-size: 11px; letter-spacing: .12em;
            color: #3a5654; font-family: ui-monospace, monospace; text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">&#9650; TOADY</div>
        <div class="card">
            <div class="check">&#10003;</div>
            <h1>You&rsquo;re unsubscribed</h1>
            <p class="lead"><b>{{ $email ?: 'This address' }}</b> won&rsquo;t receive toady broadcast emails anymore.</p>
            <p class="note">In-app and live op notifications are unaffected &mdash; this only stops broadcast email.</p>
            <a class="btn" href="{{ url('/') }}">&rarr; toady.net</a>
        </div>
        <div class="tag">ephemeral ingress mission command</div>
    </div>
</body>
</html>
