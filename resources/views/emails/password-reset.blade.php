<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password — The Lens Publication</title>
</head>
<body style="margin:0; padding:0; background-color:#f6f3f4; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color:#1c1917;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f3f4; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:580px; background-color:#ffffff; border:1px solid #e7dfe2; border-radius:14px; overflow:hidden; box-shadow:0 6px 24px rgba(92,10,24,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg, #4a091b 0%, #700f2b 100%); padding:26px 32px; text-align:left;">
                            <p style="margin:0 0 6px 0; font-size:11px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:#f3cdd6;">
                                THE LENS PUBLICATION • PUP BIÑAN
                            </p>
                            <h1 style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-size:22px; font-weight:700; color:#ffffff; line-height:1.3;">
                                Password Reset Request
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 32px 24px 32px; font-size:15px; line-height:1.65; color:#292524;">
                            <p style="margin:0 0 16px 0;">
                                Hello <strong>{{ $recipientName }}</strong>,
                            </p>

                            <p style="margin:0 0 16px 0;">
                                We received a request to reset the password for your The Lens Publication member account
                                @if(!empty($studentNumber))
                                    (<span style="font-family:monospace; color:#5c0a18; font-weight:600;">{{ $studentNumber }}</span>).
                                @else
                                    .
                                @endif
                            </p>

                            <p style="margin:0 0 24px 0;">
                                Click the button below to choose a new password. This link will expire in <strong>{{ $expireMinutes }} minutes</strong>.
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#700f2b;">
                                        <a href="{{ $resetUrl }}" target="_blank" rel="noopener noreferrer"
                                           style="display:inline-block; padding:13px 26px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; letter-spacing:0.02em;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <div style="background-color:#faf6f7; border-left:3px solid #700f2b; padding:12px 16px; border-radius:0 8px 8px 0; margin:0 0 20px 0; font-size:13px; color:#57534e;">
                                If you did not request a password reset, you can safely ignore this email—your account password remains unchanged.
                            </div>

                            <p style="margin:0; font-size:12px; color:#78716c; word-break:break-all;">
                                If the button does not work, copy and paste this link into your browser:<br>
                                <a href="{{ $resetUrl }}" style="color:#700f2b; text-decoration:underline;">{{ $resetUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 32px; background-color:#faf8f9; border-top:1px solid #efe7ea; font-size:12px; color:#78716c; text-align:center;">
                            &copy; {{ date('Y') }} The Lens Publication &mdash; Official Student Publication
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
