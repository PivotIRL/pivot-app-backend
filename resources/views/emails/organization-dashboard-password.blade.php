<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pivot Organization Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:30px 0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#ffffff; border-radius:12px; padding:40px; max-width:600px;">
                <tr>
                    <td style="padding-bottom:20px;">
                        <h1 style="margin:0; font-size:24px; color:#111827;">
                            {{ $isSetup ? 'Set up your dashboard password' : 'Reset your dashboard password' }}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:15px; color:#374151; line-height:1.6; padding-bottom:25px;">
                        @if ($isSetup)
                            You now have access to the Pivot Organization Dashboard. Choose a password to sign in.
                            This link expires in 72 hours.
                        @else
                            We received a request to reset your Pivot Organization Dashboard password.
                            This link expires in 60 minutes.
                        @endif
                        <br><br>
                        This password is only for the dashboard. Your Pivot mobile app password does not change.
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding-bottom:25px;">
                        <a href="{{ $url }}"
                           style="display:inline-block; padding:14px 28px; background:#111827; color:#ffffff; border-radius:8px; text-decoration:none; font-size:15px;">
                            {{ $isSetup ? 'Set password' : 'Reset password' }}
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:13px; color:#6b7280; line-height:1.6;">
                        If you did not expect this email, you can ignore it. Your password will not change.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
