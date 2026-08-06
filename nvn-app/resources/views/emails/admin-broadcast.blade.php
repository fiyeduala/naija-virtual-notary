@php
    $logo = \App\Support\Branding::logoUrl();
    // Email clients need absolute URLs; Branding returns a site-relative path.
    $logoUrl = $logo ? rtrim(config('app.url'), '/') . $logo : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
{{-- Inline styles throughout: <style> blocks are stripped by Gmail and Outlook. --}}
<body style="margin:0; padding:0; background:#f9fafb; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border:1px solid #e3e6ea; border-radius:12px; overflow:hidden;">

                <tr>
                    <td style="padding:24px 28px; border-bottom:1px solid #e3e6ea;">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" style="height:34px; width:auto; display:block; border:0;">
                        @else
                            <div style="font-size:17px; font-weight:700; color:#3d8a27;">Naija Virtual Notary</div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px; font-size:15px; line-height:1.65; color:#1f2933;">
                        {{-- Admin-authored HTML, inserted verbatim and never compiled as Blade. --}}
                        {!! $bodyHtml !!}
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 28px; background:#f9fafb; border-top:1px solid #e3e6ea; font-size:12px; line-height:1.6; color:#5f6b7a;">
                        <div>© {{ date('Y') }} Naija Virtual Notary</div>
                        @if ($unsubscribe)
                            <div style="margin-top:8px;">
                                You are receiving this because you have an account with us.
                                <a href="{{ $unsubscribe }}" style="color:#5f6b7a; text-decoration:underline;">Stop receiving announcements</a>.
                                Emails about your own requests will still reach you.
                            </div>
                        @endif
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
