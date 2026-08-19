<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your {{ $periodLabel }} TierHome Report</title>
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
                            <p style="margin:0 0 8px; color:#111827; font-size:18px; font-weight:700;">Your {{ $periodLabel }} Report</p>
                            <p style="margin:0 0 24px; color:#4b5563; font-size:14px; line-height:1.6;">
                                Hi {{ $recipientName }}, here's a quick summary of your properties.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="padding:12px 16px; background-color:#f0fdf4; border-radius:10px 10px 0 0;">
                                        <span style="color:#166534; font-size:12px; font-weight:600; text-transform:uppercase;">Revenue</span><br>
                                        <span style="color:#111827; font-size:20px; font-weight:700;">₱{{ number_format($stats['revenue'], 2) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; background-color:#fef2f2;">
                                        <span style="color:#991b1b; font-size:12px; font-weight:600; text-transform:uppercase;">Expenses</span><br>
                                        <span style="color:#111827; font-size:20px; font-weight:700;">₱{{ number_format($stats['expenses'], 2) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; background-color:{{ $stats['net_income'] >= 0 ? '#f0fdf4' : '#fef2f2' }}; border-radius:0 0 10px 10px;">
                                        <span style="color:#374151; font-size:12px; font-weight:600; text-transform:uppercase;">Net Income</span><br>
                                        <span style="color:#111827; font-size:20px; font-weight:700;">₱{{ number_format($stats['net_income'], 2) }}</span>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="padding:6px 0; color:#4b5563; font-size:13px;">Active Listings</td>
                                    <td style="padding:6px 0; color:#111827; font-size:13px; font-weight:600; text-align:right;">{{ $stats['active_listings'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0; color:#4b5563; font-size:13px;">Pending Reservations</td>
                                    <td style="padding:6px 0; color:#111827; font-size:13px; font-weight:600; text-align:right;">{{ $stats['pending_reservations'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0; color:#4b5563; font-size:13px;">Open Maintenance Requests</td>
                                    <td style="padding:6px 0; color:#111827; font-size:13px; font-weight:600; text-align:right;">{{ $stats['pending_maintenance'] }}</td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color:#3d6b35; border-radius:10px;">
                                        <a href="{{ $dashboardUrl }}" style="display:inline-block; padding:12px 24px; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none;">
                                            View Full Dashboard
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 32px; border-top:1px solid #f3f4f6;">
                            <p style="margin:0; color:#9ca3af; font-size:11px;">
                                You're receiving this because you enabled {{ $periodLabel }} report emails on TierHome. You can turn this off anytime from your Reports page.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>