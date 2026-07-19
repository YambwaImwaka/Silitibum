<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="440" cellpadding="0" cellspacing="0"
                   style="background:#ffffff;border-radius:8px;padding:32px;">
                <tr>
                    <td style="font-size:18px;font-weight:bold;color:#111;padding-bottom:12px;">
                        {{ config('app.name') }}
                    </td>
                </tr>
                <tr>
                    <td style="font-size:14px;color:#444;line-height:1.5;padding-bottom:20px;">
                        @if ($type === \App\Models\VerificationCode::TYPE_RESET_PASSWORD)
                            Use the code below to reset your password. If you didn't request this, you can ignore this email.
                        @else
                            Use the code below to verify your email address.
                        @endif
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding-bottom:20px;">
                        <span style="display:inline-block;font-size:30px;letter-spacing:8px;font-weight:bold;color:#111;background:#f4f4f7;border-radius:6px;padding:12px 20px;">{{ $code }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:12px;color:#888;">
                        This code expires in {{ \App\Models\VerificationCode::EXPIRY_MINUTES }} minutes.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
