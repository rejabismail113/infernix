<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Certificate verification</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#1f2937;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 32px;max-width:420px;width:calc(100% - 32px);text-align:center}
.ok{color:#15803d}.bad{color:#b91c1c}.muted{color:#6b7280;font-size:14px}h1{font-size:20px;margin:8px 0}</style></head>
<body><div class="card">
@if($found && $valid)
 <div class="ok" style="font-size:40px">✓</div><h1 class="ok">Valid certificate</h1>
 <p><strong>{{ $serial }}</strong></p><p>{{ $course }}</p><p class="muted">Issued {{ $issued }} by {{ $issuer }}</p>
@elseif($found)
 <div class="bad" style="font-size:40px">✕</div><h1 class="bad">This certificate has been revoked</h1><p class="muted">Serial {{ $serial }}</p>
@else
 <div class="bad" style="font-size:40px">?</div><h1 class="bad">Certificate not found</h1><p class="muted">Check the link or QR code and try again.</p>
@endif
</div></body></html>
