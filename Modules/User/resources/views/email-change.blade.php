<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<link rel="icon" href="{{ config('app.logo_url', rtrim((string) config('app.url'), '/') . '/logo.png') }}">
<title>Confirm your new email | {{ config('app.name') }}</title>
<style>
  *{box-sizing:border-box} body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#F5F8FD;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#04143B}
  .card{width:100%;max-width:440px;background:#fff;border-radius:20px;padding:36px 32px;text-align:center;box-shadow:0 20px 40px -20px rgba(4,20,59,.25)}
  .card img{width:120px;margin:0 auto 22px;display:block}
  h1{margin:0 0 12px;font-size:24px;line-height:30px;font-weight:800}
  p{margin:0 0 22px;font-size:16px;line-height:25px;color:#3B4A6B} p:last-child{margin-bottom:0}
  .mail{font-weight:700;color:#04143B;overflow-wrap:anywhere}
  button{appearance:none;border:0;cursor:pointer;background:#0057D9;color:#fff;font:inherit;font-size:16px;font-weight:700;padding:14px 28px;border-radius:12px}
  button:hover{background:#004DC0} button:focus-visible{outline:3px solid #9CC0FF;outline-offset:2px}
  .ok{width:56px;height:56px;margin:0 auto 18px;border-radius:50%;background:#E7F7EC;color:#00A82D;display:grid;place-items:center;font-size:30px;font-weight:700}
  .bad{background:#FDECEC;color:#D64545}
</style>
</head>
<body>
<main class="card">
  <img src="{{ config('app.logo_url', rtrim((string) config('app.url'), '/') . '/logo.png') }}" alt="{{ config('app.name') }}">

  @if ($state === 'ready')
    <h1>Confirm your new email</h1>
    <p>Use <span class="mail">{{ $email }}</span> as the email for your {{ config('app.name') }} account?</p>
    <form method="post">@csrf<button type="submit">Yes, confirm</button></form>
  @elseif ($state === 'done')
    <div class="ok" aria-hidden="true">&#10003;</div>
    <h1>Email confirmed</h1>
    <p>Your account email is now <span class="mail">{{ $email }}</span>. Open the {{ config('app.name') }} app and sign in with it and your usual password.</p>
  @elseif ($state === 'taken')
    <div class="ok bad" aria-hidden="true">!</div>
    <h1>This email is already in use</h1>
    <p>Another account now uses that address, so nothing was changed. Please contact support.</p>
  @else
    <div class="ok bad" aria-hidden="true">!</div>
    <h1>This link is no longer valid</h1>
    <p>It may have expired or already been used. If you still need to change your email, please contact support.</p>
  @endif
</main>
</body>
</html>