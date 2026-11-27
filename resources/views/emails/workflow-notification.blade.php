<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f6f3f4; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color:#1c1917;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f3f4; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px; background-color:#ffffff; border:1px solid #e7dfe2; border-radius:14px; overflow:hidden; box-shadow:0 6px 24px rgba(92,10,24,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg, #4a091b 0%, #700f2b 100%); padding:26px 32px; text-align:left;">
                            <p style="margin:0 0 6px 0; font-size:11px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:#f3cdd6;">
                                THE LENS PUBLICATION • EDITORIAL WORKFLOW
                            </p>
                            <h1 style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-size:21px; font-weight:700; color:#ffffff; line-height:1.3;">
                                {{ $headline }}
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 32px 24px 32px; font-size:15px; line-height:1.65; color:#292524;">
                            <p style="margin:0 0 14px 0;">
                                Hello <strong>{{ $recipientName }}</strong>,
                            </p>

                            <p style="margin:0 0 18px 0;">
                                {{ $summary }}
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:transparent; border-top:1px solid #e7e5e4; border-bottom:1px solid #e7e5e4; margin:0 0 20px 0;">
                                <tr>
                                    <td style="padding:12px 0; font-size:13px; color:#44403c;">
                                        <p style="margin:0{{ !empty($statusLabel) ? ' 0 6px 0' : '' }};">
                                            <strong style="color:#5c0a18;">{{ $itemLabel }}:</strong> {{ $itemTitle }}
                                        </p>
                                        @if(!empty($statusLabel))
                                            <p style="margin:0;">
                                                <strong style="color:#5c0a18;">Status:</strong> {{ $statusLabel }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            @if(!empty($remarks))
                                <div style="background-color:#fff6f7; border-left:4px solid #b42318; padding:14px 18px; border-radius:0 10px 10px 0; margin:0 0 22px 0;">
                                    <p style="margin:0 0 6px 0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#b42318;">
                                        Editor Remarks
                                    </p>
                                    <p style="margin:0; font-size:14px; color:#292524; white-space:pre-line;">{{ $remarks }}</p>
                                </div>
                            @endif

                            @if(!empty($actionUrl) && !empty($actionText))
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:4px 0 18px 0;">
                                    <tr>
                                        <td style="border-radius:8px; background-color:#700f2b;">
                                            <a href="{{ $actionUrl }}" target="_blank" rel="noopener noreferrer"
                                               style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; letter-spacing:0.02em;">
                                                {{ $actionText }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin:0; font-size:12px; color:#78716c; word-break:break-all;">
                                    Direct link: <a href="{{ $actionUrl }}" style="color:#700f2b; text-decoration:underline;">{{ $actionUrl }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 32px; background-color:#faf8f9; border-top:1px solid #efe7ea; font-size:12px; color:#78716c; text-align:center;">
                            &copy; {{ date('Y') }} The Lens Publication &mdash; Automated Editorial Notification
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
