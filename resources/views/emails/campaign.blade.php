<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $header ?? 'toady' }}</title>
</head>
<body style="margin:0;padding:0;background:#0b0f0e;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:24px 12px;">
        <div style="background:#111614;border:1px solid #1e2926;border-radius:12px;overflow:hidden;">
            <div style="padding:14px 20px;border-bottom:1px solid #1e2926;">
                <span style="font-family:'Courier New',monospace;color:#1cf0a0;font-size:18px;letter-spacing:.18em;">&#9650; toady</span>
            </div>
            @if(!empty($header))
                <div style="padding:20px 20px 0;">
                    <h1 style="margin:0;font-size:20px;line-height:1.3;color:#eaf2ef;">{{ $header }}</h1>
                </div>
            @endif
            <div style="padding:18px 20px;line-height:1.6;color:#cdd8d4;font-size:15px;">
                {!! $bodyHtml !!}
            </div>
            @if(!empty($signature))
                <div style="padding:0 20px 20px;color:#9fb0aa;font-size:14px;line-height:1.5;">{!! nl2br(e($signature)) !!}</div>
            @endif
        </div>
        <div style="padding:14px 20px;text-align:center;color:#6b7d77;font-size:12px;">
            @if(!empty($unsubscribeUrl))
                <a href="{{ $unsubscribeUrl }}" style="color:#6b7d77;text-decoration:underline;">Unsubscribe</a> from these emails.
            @endif
        </div>
    </div>
</body>
</html>
