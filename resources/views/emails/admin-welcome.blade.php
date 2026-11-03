<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Your Synsyma admin account</title>
<style>
  @media only screen and (max-width: 480px) {
    .px { padding-left: 22px !important; padding-right: 22px !important; }
    .h1 { font-size: 24px !important; line-height: 30px !important; }
    .btn a { display: block !important; }
    .cred { font-size: 15px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#F5F8FD;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  {{-- Preview text shown next to the subject in the inbox --}}
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
    {{ $isReset ? 'Your new Synsyma login details are inside.' : 'Your Synsyma admin account is ready. Sign in with the details inside.' }}
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F5F8FD" style="background-color:#F5F8FD;">
    <tr>
      <td align="center" style="padding:32px 16px 40px;">

        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;">

          {{-- Header --}}
          <tr>
            <td align="center" bgcolor="#04143B" style="background-color:#04143B;border-radius:20px 20px 0 0;padding:34px 24px 30px;">
              <img src="{{ $logoUrl }}" width="132" alt="Synsyma"
                   style="display:block;border:0;outline:none;width:132px;max-width:100%;height:auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;font-weight:700;color:#FFFFFF;text-align:center;">
            </td>
          </tr>

          {{-- Brand accent bar: blue to cyan to green --}}
          <tr>
            <td height="4" bgcolor="#0066FF" style="height:4px;font-size:0;line-height:0;background-color:#0066FF;background-image:linear-gradient(90deg,#0066FF 0%,#00C2FF 55%,#8EF500 100%);">&nbsp;</td>
          </tr>

          {{-- Heading and intro --}}
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:38px 40px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td bgcolor="#E8F1FF" style="background-color:#E8F1FF;border-radius:999px;padding:6px 14px;font-size:12px;line-height:16px;font-weight:700;color:#0057D9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                    Admin dashboard
                  </td>
                </tr>
              </table>

              <h1 class="h1" style="margin:20px 0 0;font-size:28px;line-height:34px;font-weight:800;color:#04143B;letter-spacing:-0.3px;">
                {{ $isReset ? 'Your new login details' : 'Your admin account is ready' }}
              </h1>

              <p style="margin:16px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;">Hi {{ $name }},</p>
              <p style="margin:8px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;">
                @if ($isReset)
                  {{ !empty($createdBy) ? $createdBy : 'A super admin' }} has sent you new login details. Your previous password no longer works. Use the details below to sign in.
                @else
                  {{ !empty($createdBy) ? $createdBy : 'A super admin' }} has set you up on the Synsyma dashboard. Use the details below to sign in.
                @endif
              </p>
            </td>
          </tr>

          {{-- Login details --}}
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:24px 40px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#EEF4FF" style="background-color:#EEF4FF;border:1px solid #CFE0FF;border-radius:16px;">
                <tr>
                  <td style="padding:20px 22px 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#5A6785;">Email</td>
                </tr>
                <tr>
                  <td class="cred" style="padding:0 22px 16px;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:17px;line-height:24px;font-weight:700;color:#04143B;word-break:break-all;">{{ $email }}</td>
                </tr>
                <tr><td style="padding:0 22px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" bgcolor="#CFE0FF" style="height:1px;font-size:0;line-height:0;background-color:#CFE0FF;">&nbsp;</td></tr></table></td></tr>
                <tr>
                  <td style="padding:16px 22px 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#5A6785;">Temporary password</td>
                </tr>
                <tr>
                  <td class="cred" style="padding:0 22px 20px;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:20px;line-height:26px;font-weight:700;letter-spacing:1px;color:#04143B;word-break:break-all;">{{ $password }}</td>
                </tr>
              </table>
            </td>
          </tr>

          @if (!empty($roles))
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:16px 40px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#5A6785;">
              Your role{{ count($roles) > 1 ? 's' : '' }}:
              @foreach ($roles as $role)
                <span style="display:inline-block;margin:2px 4px 2px 0;background-color:#E8F1FF;border-radius:999px;padding:3px 12px;font-size:13px;line-height:18px;font-weight:700;color:#0057D9;">{{ $role }}</span>
              @endforeach
            </td>
          </tr>
          @endif

          {{-- Button --}}
          <tr>
            <td class="px btn" bgcolor="#FFFFFF" align="center" style="background-color:#FFFFFF;padding:28px 40px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center" bgcolor="#0066FF" style="background-color:#0066FF;border-radius:12px;">
                    <a href="{{ $loginUrl }}" target="_blank"
                       style="display:inline-block;padding:15px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px;">
                      Sign in to the dashboard
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Safety note --}}
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:24px 40px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td width="4" bgcolor="#00B32C" style="width:4px;background-color:#00B32C;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
                  <td bgcolor="#F3FBF3" style="background-color:#F3FBF3;border-radius:0 12px 12px 0;padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#2F4A36;">
                    <strong style="color:#04143B;">Keep these details private.</strong>
                    Synsyma will never ask you for your password. If anyone else has seen it, ask a super admin to reset it.
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Not you --}}
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;border-radius:0 0 20px 20px;padding:28px 40px 38px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#5A6785;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td height="1" bgcolor="#E6ECF5" style="height:1px;font-size:0;line-height:0;background-color:#E6ECF5;">&nbsp;</td></tr>
              </table>
              <p style="margin:20px 0 0;">
                Weren't expecting this? You can ignore this email and let a super admin know. The account stays unused until someone signs in.
              </p>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td align="center" style="padding:28px 24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:20px;color:#5A6785;">
              <strong style="color:#04143B;">{{config('app.name')}}</strong><br>
              <span style="display:inline-block;margin-top:10px;">
                Need help? <a href="mailto:{{ $supportEmail }}" style="color:#0057D9;text-decoration:underline;">{{ $supportEmail }}</a>
              </span><br>
              <span style="display:inline-block;margin-top:10px;color:#7A86A3;">&copy; {{ date('Y') }} {{config('app.name')}}. All rights reserved.</span>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>