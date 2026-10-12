@php
    $logo    = \App\Support\Branding::logoUrl();
    $logoUrl = $logo ? rtrim(config('app.url'), '/') . $logo : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Stops the sign-in link in the button leaking this page's URL onwards. --}}
    <meta name="referrer" content="no-referrer">
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
                            <img src="{{ $logoUrl }}" alt="Naija Virtual Notary" style="height:34px; width:auto; display:block; border:0;">
                        @else
                            <div style="font-size:17px; font-weight:700; color:#3d8a27;">Naija Virtual Notary</div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 28px 8px; font-size:15px; line-height:1.65; color:#1f2933;">
                        @foreach ($paragraphs as $paragraph)
                            {{-- Escaped: the body is administrator-editable plain text. --}}
                            <p style="margin:0 0 16px;">{!! nl2br(e($paragraph)) !!}</p>
                        @endforeach
                    </td>
                </tr>

                @if ($actionText && $actionUrl)
                    <tr>
                        <td style="padding:4px 28px 28px;">
                            <a href="{{ $actionUrl }}" rel="noreferrer"
                               style="display:inline-block; padding:12px 22px; background:#3d8a27; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none; border-radius:8px;">
                                {{ $actionText }}
                            </a>
                        </td>
                    </tr>
                @elseif ($actionText)
                    <tr>
                        <td style="padding:4px 28px 28px; font-size:13px; color:#5f6b7a;">
                            [{{ $actionText }} — the link appears here in the sent email]
                        </td>
                    </tr>
                @endif

                @if ($footnote)
                    <tr>
                        <td style="padding:0 28px 24px; font-size:13px; line-height:1.6; color:#5f6b7a;">
                            {{ $footnote }}
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 28px; background:#f9fafb; border-top:1px solid #e3e6ea; font-size:12px; line-height:1.6; color:#5f6b7a;">
                        <div>© {{ date('Y') }} Naija Virtual Notary</div>
                        @if ($replyTo)
                            <div style="margin-top:8px;">
                                Questions about your application? Reply to this email, or write to
                                <a href="mailto:{{ $replyTo }}" style="color:#5f6b7a;">{{ $replyTo }}</a>.
                            </div>
                        @endif
                        <div style="margin-top:8px;">
                            This email is about a job application you made. It is not an advertisement and
                            you are not subscribed to anything.
                        </div>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
