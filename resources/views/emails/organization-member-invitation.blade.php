<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Join {{ $organization->name }} on Pivot</title>
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
                            You're invited to join {{ $organization->name }}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:15px; color:#374151; line-height:1.6; padding-bottom:25px;">
                        Hi {{ $user->name }},<br><br>
                        {{ $organization->name }} has invited you to join their organization on Pivot.
                        Open the link on your phone and sign in to the Pivot app with this email address to accept.
                        <br><br>
                        This link expires on {{ $expiresAt->format('j F Y') }}.
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding-bottom:25px;">
                        <a href="{{ $link }}"
                           style="display:inline-block; padding:14px 28px; background:#111827; color:#ffffff; border-radius:8px; text-decoration:none; font-size:15px;">
                            Accept invitation
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:13px; color:#6b7280; line-height:1.6;">
                        If you did not expect this invitation, you can ignore this email. Nothing changes until you accept.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
