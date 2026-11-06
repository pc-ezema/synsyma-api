@extends('emails.contractor-layout')

@php
    $rows = array_values(array_filter([
        ['Applicant', $c->name . ' (' . $c->type . ')'],
        $c->type === 'company' && $c->contact_name ? ['Contact person', $c->contact_name] : null,
        ['Email', $c->email],
        ['Phone', $c->phone],
        ['Location', $c->city . ', ' . $c->region . ', ' . $c->country],
        ['Services', $categories ?: 'None chosen'],
        ['Source', $campaign ? 'QR code: ' . $campaign : 'Landing page'],
    ]));
    $label = "padding:%s 22px 4px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#5A6785;";
@endphp

@section('preview', 'New contractor application from ' . $c->name)
@section('badge', 'New application')
@section('title', 'A contractor wants to join')

@section('intro')
  <p style="margin:16px 0 0;font-size:16px;line-height:26px;color:#3B4A6B;">
    A new contractor application is waiting for review. Here are the details.
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
            <td style="padding:0 22px {{ $loop->last ? '18px' : '14px' }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:24px;font-weight:700;color:#04143B;overflow-wrap:anywhere;">{{ $row[1] }}</td>
          </tr>
        @endforeach
      </table>
    </td>
  </tr>

  <tr>
    <td class="px btn" bgcolor="#FFFFFF" align="center" style="background-color:#FFFFFF;padding:26px 40px 8px;">
      @if ($link)
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" bgcolor="#0066FF" style="background-color:#0066FF;border-radius:12px;">
            <a href="{{ $link }}" target="_blank"
               style="display:inline-block;padding:15px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px;">
              Review application
            </a>
          </td>
        </tr>
      </table>
      @else
      <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#5A6785;">Open the admin dashboard, Contractors, Pending tab to review it.</p>
      @endif
    </td>
  </tr>

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
  This alert goes to everyone listed in your team alert emails whenever a new application arrives.
@endsection