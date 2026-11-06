@extends('emails.contractor-layout')

@php
    $badges = [
        'applied' => 'Application received', 'added' => 'Welcome', 'approved' => 'Approved',
        'rejected' => 'Application update', 'suspended' => 'Account notice', 'reinstated' => 'Account active',
    ];
    $titles = [
        'applied' => 'We received your application', 'added' => 'Welcome to ' . $app, 'approved' => "You're approved",
        'rejected' => 'Update on your application', 'suspended' => 'Your account is suspended', 'reinstated' => 'Your account is active again',
    ];
    $p = 'margin:8px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;';
@endphp

@section('preview', $titles[$event] ?? $app)
@section('badge', $badges[$event] ?? 'Contractor')
@section('title', $titles[$event] ?? $app)

@section('intro')
  <p style="{{ $p }}margin-top:16px;">Hi {{ $name }},</p>

  @if ($event === 'applied')
    <p style="{{ $p }}">Thank you for applying to join {{ $app }}. We have received your application and our team will review it. We will contact you by email or phone once we have a decision.</p>
  @elseif ($event === 'added')
    <p style="{{ $p }}">You have been added as a contractor on {{ $app }}, and your account is approved. Our team will contact you directly when there is a job for you.</p>
  @elseif ($event === 'approved')
    <p style="{{ $p }}">Good news: your application has been approved and you are now a contractor on {{ $app }}. Our team will contact you directly when there is a job that matches your services and area.</p>
  @elseif ($event === 'rejected')
    <p style="{{ $p }}">Thank you for your interest in {{ $app }}. After reviewing your application, we are not able to approve it at this time.</p>
  @elseif ($event === 'suspended')
    <p style="{{ $p }}">Your contractor account on {{ $app }} has been suspended. You will not be assigned new jobs while it is suspended.</p>
  @elseif ($event === 'reinstated')
    <p style="{{ $p }}">Your contractor account on {{ $app }} is active again, and you can be assigned jobs as before. Thank you for your patience.</p>
  @endif
@endsection

@section('blocks')
  @if (in_array($event, ['rejected', 'suspended']) && !empty($reason))
  {{-- Reason (red note) --}}
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:24px 40px 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="4" bgcolor="#D92D4A" style="width:4px;background-color:#D92D4A;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#FFF4F6" style="background-color:#FFF4F6;border-radius:0 12px 12px 0;padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#5B2430;">
            <strong style="color:#04143B;">Reason:</strong> {{ $reason }}
          </td>
        </tr>
      </table>
    </td>
  </tr>
  @endif

  @if (in_array($event, ['applied', 'added', 'approved', 'reinstated']))
  {{-- What happens next (green note) --}}
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:24px 40px 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="4" bgcolor="#00B32C" style="width:4px;background-color:#00B32C;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#F3FBF3" style="background-color:#F3FBF3;border-radius:0 12px 12px 0;padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#2F4A36;">
            @if ($event === 'applied')
              <strong style="color:#04143B;">You do not need to do anything else for now.</strong>
            @else
              <strong style="color:#04143B;">Need to change your details?</strong> Reply to this email and our team will update them.
            @endif
          </td>
        </tr>
      </table>
    </td>
  </tr>
  @endif

  @if (!empty($supportEmail))
  {{-- Button --}}
  <tr>
    <td class="px btn" bgcolor="#FFFFFF" align="center" style="background-color:#FFFFFF;padding:26px 40px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" bgcolor="#0066FF" style="background-color:#0066FF;border-radius:12px;">
            <a href="mailto:{{ $supportEmail }}" target="_blank"
               style="display:inline-block;padding:15px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px;">
              Contact our team
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  @endif
@endsection

@section('closing')
  This message was sent because of your contractor record with {{ $app }}.
@endsection