<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Pivot invitation</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f4f6f8; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 420px; width: 100%; text-align: center; color: #374151; line-height: 1.6; }
        h1 { font-size: 22px; color: #111827; margin: 0 0 12px; }
        .button { display: block; margin: 20px 0 10px; padding: 14px; background: #111827; color: #fff; border-radius: 8px; text-decoration: none; }
        .stores a { color: #111827; margin: 0 8px; font-size: 14px; }
    </style>
</head>
<body>
<div class="card">
    @if ($state === 'valid')
        <h1>Join {{ $organizationName }} on Pivot</h1>
        <p>Open this invitation in the Pivot app, then sign in or create an account with the email address it was sent to.</p>
        <p>Your invitation code:<br><strong style="font-size:24px; letter-spacing:4px;">{{ $code }}</strong></p>
        @if ($appLink)
            <a class="button" href="{{ $appLink }}">Open in Pivot app</a>
        @endif
        <p class="stores">
            Don't have the app?<br>
            <a href="{{ $iosStoreUrl }}">App Store</a>
            <a href="{{ $androidStoreUrl }}">Google Play</a>
        </p>
    @elseif ($state === 'joined')
        <h1>You're already a member</h1>
        <p>You have already joined {{ $organizationName }}. Open the Pivot app to continue.</p>
    @elseif ($state === 'expired')
        <h1>This invitation has expired</h1>
        <p>Ask your organization administrator to send you a new invitation.</p>
    @else
        <h1>This invitation is no longer valid</h1>
        <p>Ask your organization administrator to send you a new invitation.</p>
    @endif
</div>
</body>
</html>
