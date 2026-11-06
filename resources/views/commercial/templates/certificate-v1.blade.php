{{-- Infernix training certificate v1 — landscape, serial number + QR verification link (M03-06).
     The public verify page reveals only validity, never personal data. --}}
@php($accent = $settings['accent_color'] ?? '#ea580c')
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $doc['number'] }}</title>
<style>
 @page { size: A4 landscape; margin: 12mm; }
 body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1f2937; text-align: center; }
 .frame { border: 6px double {{ $accent }}; padding: 26px 40px; height: 160mm; position: relative; }
 h1 { font-size: 34px; letter-spacing: 4px; color: {{ $accent }}; margin: 10px 0 4px; }
 .name { font-size: 28px; font-weight: bold; margin: 18px 0 4px; border-bottom: 1px solid #d1d5db; display: inline-block; padding: 0 30px 4px; }
 .course { font-size: 18px; font-weight: bold; margin: 8px 0; }
 .muted { color: #6b7280; } table { width: 100%; border-collapse: collapse; margin-top: 30px; }
 .void { position: fixed; top: 30%; left: 18%; font-size: 120px; color: rgba(220, 38, 38, 0.18); transform: rotate(-20deg); font-weight: bold; letter-spacing: 12px; }
</style></head><body>
@if($void)<div class="void">VOID</div>@endif
<div class="frame">
 @if($logo)<img src="{{ $logo }}" alt="" style="height:46px">@endif
 <div class="muted">{{ $issuer['name'] ?? '' }}</div>
 <h1>{{ $settings['title'] ?? 'CERTIFICATE OF COMPLETION' }}</h1>
 <div class="muted">This is to certify that</div>
 <div class="name">{{ $counterparty['name'] ?? '' }}</div>
 @if(!empty($counterparty['organisation']))<div class="muted">{{ $counterparty['organisation'] }}</div>@endif
 <div class="muted" style="margin-top:14px">has successfully completed</div>
 <div class="course">{{ $subject }}</div>
 <div class="muted">on {{ $effective_date }}@if($trainer) · trainer: {{ $trainer }}@endif</div>
 <table><tr>
  <td style="text-align:left;vertical-align:bottom;width:40%"><div style="border-top:1px solid #9ca3af;padding-top:4px;width:200px">{{ $issuer['signatory_name'] ?? '' }}<br><span class="muted">{{ $issuer['signatory_title'] ?? 'Authorised signatory' }}</span></div></td>
  <td style="text-align:center;vertical-align:bottom" class="muted">Serial no.<br><strong style="color:#1f2937">{{ $doc['number'] }}</strong><br>Issued {{ $doc['issue_date'] }}</td>
  <td style="text-align:right;vertical-align:bottom;width:30%">@if($qr)<img src="{{ $qr }}" alt="" style="height:90px"><br><span class="muted" style="font-size:8px">Verify: {{ $verify_url }}</span>@endif</td>
 </tr></table>
</div>
</body></html>
