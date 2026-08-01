<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $notifTitle }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:480px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.06);">

                    <tr>
                        <td style="background-color:#3d6b35; padding:24px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:700;">TierHome</p>
                            <p style="margin:2px 0 0; color:#d1e7d1; font-size:12px;">Angeles City · Verified Rentals</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px; color:#111827; font-size:18px; font-weight:700;">{{ $notifTitle }}</p>
                            <p style="margin:0 0 24px; color:#4b5563; font-size:14px; line-height:1.6;">
                                Hi {{ $recipientName }},<br><br>
                                {{ $notifMessage }}
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color:#3d6b35; border-radius:10px;">
                                        <a href="{{ $fullLink }}" style="display:inline-block; padding:12px 24px; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none;">
                                            View on TierHome
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 32px; border-top:1px solid #f3f4f6;">
                            <p style="margin:0; color:#9ca3af; font-size:11px;">
                                You're receiving this because you have an account on TierHome. This is an automated message, please don't reply directly to this email.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
