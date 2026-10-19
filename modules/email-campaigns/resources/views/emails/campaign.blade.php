<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{ $subject }}</title>
<style>
body{margin:0;padding:0;background:#F1F5F9;}
a{text-decoration:none;}
/* Admin-written body: same type scale as the welcome / subscription emails */
.eb h1{font-family:Helvetica,Arial,sans-serif;font-size:34px;font-weight:800;margin:0 0 16px;line-height:1.15;letter-spacing:-.02em;background:linear-gradient(135deg,#06B6D4 0%,#4F46E5 55%,#8B5CF6 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:#1D4ED8;}
.eb h2{font-family:Helvetica,Arial,sans-serif;font-size:20px;font-weight:700;color:#0F172A;margin:28px 0 10px;line-height:1.3;}
.eb h3{font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;color:#0F172A;margin:20px 0 6px;}
.eb p{font-family:Helvetica,Arial,sans-serif;font-size:16px;color:#64748B;line-height:1.75;margin:0 0 20px;}
.eb strong{color:#0F172A;}
.eb a{color:#2563EB;font-weight:600;}
.eb ul,.eb ol{padding-left:22px;margin:0 0 20px;}
.eb li{font-family:Helvetica,Arial,sans-serif;font-size:16px;color:#64748B;line-height:1.75;margin-bottom:8px;}
.eb img{max-width:100%;border-radius:10px;height:auto;}
@media(max-width:600px){
  .w600{width:100%!important;}
  .px{padding-left:28px!important;padding-right:28px!important;}
  .h1{font-size:28px!important;}
  .btn a{padding:15px 36px!important;font-size:15px!important;}
}
</style>
</head>
<body style="margin:0;padding:0;background:#F1F5F9;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
@if(!empty($preheader))
{{-- Inbox preview text: shown in the message list, hidden in the opened email --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $preheader }}&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" style="padding:48px 16px;">
  <table role="presentation" class="w600" width="560" cellpadding="0" cellspacing="0" border="0"
         style="border-radius:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07),0 12px 40px rgba(37,99,235,.09);">

    <tr>
      <td align="center" style="background:#F8FAFF;background-color:#F8FAFF;padding:30px 16px 26px;">
        @include('emails.partials.logo-white')
      </td>
    </tr>
    <tr><td height="4" style="background:linear-gradient(90deg,#06B6D4,#8B5CF6);font-size:0;line-height:0;">&zwnj;</td></tr>

    <tr>
      <td class="px eb" style="background:#ffffff;padding:44px 48px;">

        @if(!empty($eyebrow))
        <div style="margin-bottom:20px;">
          <span style="display:inline-block;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:50px;padding:6px 18px;font-family:Helvetica,Arial,sans-serif;font-size:11px;font-weight:700;color:#4F46E5;letter-spacing:.07em;text-transform:uppercase;">{{ $eyebrow }}</span>
        </div>
        @endif

        @if(!empty($headline))
        <h1 class="h1" style="font-family:Helvetica,Arial,sans-serif;font-size:34px;font-weight:800;margin:0 0 16px;line-height:1.15;letter-spacing:-.02em;background:linear-gradient(135deg,#06B6D4 0%,#4F46E5 55%,#8B5CF6 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:#1D4ED8;">{{ $headline }}</h1>
        @endif

        {!! $body !!}

        @if(!empty($buttonUrl) && !empty($buttonLabel))
        <table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 36px;">
          <tr>
            <td style="background:linear-gradient(135deg,#2563EB,#7C3AED);border-radius:50px;box-shadow:0 4px 6px rgba(37,99,235,.15),0 10px 30px rgba(37,99,235,.3);">
              <a href="{{ $buttonUrl }}" style="display:block;padding:17px 52px;font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;color:#ffffff;letter-spacing:.01em;text-decoration:none;white-space:nowrap;">{{ $buttonLabel }} &nbsp;→</a>
            </td>
          </tr>
        </table>
        @endif

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" style="background:#F1F5F9;">&zwnj;</td></tr></table>
        <p style="font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#94A3B8;line-height:1.6;margin:20px 0 0;">Questions? <a href="{{ config('app.url') }}/contact" style="color:#2563EB;font-weight:600;">Contact support</a>.</p>

      </td>
    </tr>

    <tr>
      <td class="px" align="center" style="background:#F8FAFC;border-top:1px solid #E2E8F0;padding:24px 48px;">
        <p style="font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#94A3B8;margin:0 0 8px;line-height:1.6;">{{ $reason }}</p>
        <p style="font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#CBD5E1;margin:0 0 12px;">© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
          <tr>
            <td style="font-family:Helvetica,Arial,sans-serif;font-size:11px;padding:0 8px;"><a href="{{ config('app.url') }}" style="color:#94A3B8;">Website</a></td>
            <td style="color:#CBD5E1;">·</td>
            <td style="font-family:Helvetica,Arial,sans-serif;font-size:11px;padding:0 8px;"><a href="{{ route('pricing') }}" style="color:#94A3B8;">Pricing</a></td>
            <td style="color:#CBD5E1;">·</td>
            <td style="font-family:Helvetica,Arial,sans-serif;font-size:11px;padding:0 8px;"><a href="{{ config('app.url') }}/contact" style="color:#94A3B8;">Contact</a></td>
          </tr>
        </table>
        <p style="font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#94A3B8;margin:14px 0 0;"><a href="{{ $unsubscribeUrl }}" style="color:#94A3B8;text-decoration:underline;">Unsubscribe</a> from marketing emails</p>
      </td>
    </tr>

  </table>
</td></tr>
</table>
@if(!empty($pixelUrl))<img src="{{ $pixelUrl }}" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">@endif
</body>
</html>
