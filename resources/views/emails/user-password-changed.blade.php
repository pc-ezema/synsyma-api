@extends('emails.contractor-layout')

@php
    $action = $viaReset ? 'reset' : 'changed';
    $label = "padding:%s 22px 4px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#5A6785;";
    $rows = array_values(array_filter([
        ['When', $changedAt],
        ['Device', $device],
        !empty($ip) ? ['IP address', $ip] : null,
    ]));
@endphp

@section('preview', 'The password for your Synsyma account was ' . $action . ' on ' . $changedAt . '.')
@section('badge', 'Security notice')
@section('title', 'Your password was ' . $action)

@section('intro')
  <p style="margin:16px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;">Hi {{ $name }},</p>
  <p style="margin:8px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;">
    The password for your Synsyma account was just {{ $action }}. Here are the details.
  </p>
@endsection

@section('blocks')
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:24px 40px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#EEF4FF" style="background-color:#EEF4FF;border:1px solid #CFE0FF;border-radius:16px;">
        @foreach ($rows as $i => $row)
          @if ($i > 0)
          <tr><td style="padding:0 22px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" bgcolor="#CFE0FF" style="height:1px;font-size:0;line-height:0;background-color:#CFE0FF;">&nbsp;</td></tr></table></td></tr>
          @endif
          <tr><td style="{{ sprintf($label, $i === 0 ? '18px' : '14px') }}">{{ $row[0] }}</td></tr>
          <tr>
            <td style="padding:0 22px {{ $loop->last ? '18px' : '14px' }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:24px;font-weight:700;color:#04143B;">{{ $row[1] }}</td>
          </tr>
        @endforeach
      </table>
    </td>
  </tr>

  {{-- If it was you --}}
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:20px 40px 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="4" bgcolor="#00B32C" style="width:4px;background-color:#00B32C;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#F3FBF3" style="background-color:#F3FBF3;border-radius:0 12px 12px 0;padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#2F4A36;">
            <strong style="color:#04143B;">If this was you, there is nothing to do.</strong>
            @if (!empty($signedOutOthers)) Your other devices were signed out, so you may need to sign in again there. @endif
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- If it was not you --}}
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:14px 40px 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="4" bgcolor="#D92D4A" style="width:4px;background-color:#D92D4A;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#FFF4F6" style="background-color:#FFF4F6;border-radius:0 12px 12px 0;padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#5B2430;">
            <strong style="color:#04143B;">Didn't do this?</strong>
            Someone else may have access to your account. Contact us right away so we can help you secure it.
          </td>
        </tr>
      </table>
    </td>
  </tr>

  @if (!empty($supportEmail))
  <tr>
    <td class="px btn" bgcolor="#FFFFFF" align="center" style="background-color:#FFFFFF;padding:26px 40px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" bgcolor="#0066FF" style="background-color:#0066FF;border-radius:12px;">
            <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('I did not change my password') }}" target="_blank"
               style="display:inline-block;padding:15px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px;">
              Report this change
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  @endif
@endsection

@section('closing')
  We send this notice every time a password changes. It never includes the password itself.
@endsection