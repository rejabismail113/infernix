{{-- Infernix letter layout v1: staff memo, appointment letter, general letter. Rendered only from the issued snapshot (M03-07).
     @see SRS M03-06, M03-07, M03-08 --}}
@php($accent = $settings['accent_color'] ?? '#ea580c')
@php($addr = fn ($a) => is_array($a) ? implode(', ', array_filter([$a['line1'] ?? null, $a['line2'] ?? null, trim(($a['postcode'] ?? '').' '.($a['city'] ?? '')), $a['state'] ?? null, $a['country'] ?? null], fn ($v) => is_string($v) && trim($v) !== '')) : (string) $a)
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $doc['number'] }}</title>
<style>
 @page { margin: 18mm 16mm; }
 body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.55; }
 .muted { color: #6b7280; } table { width: 100%; border-collapse: collapse; }
 h1 { font-size: 16px; color: {{ $accent }}; margin: 0; letter-spacing: 1px; }
 .rule { border-top: 3px solid {{ $accent }}; margin: 10px 0 16px; }
 .subject { font-weight: bold; font-size: 12.5px; margin: 14px 0 8px; }
 .void { position: fixed; top: 38%; left: 8%; font-size: 110px; color: rgba(220, 38, 38, 0.18); transform: rotate(-30deg); font-weight: bold; letter-spacing: 12px; }
 .sig { margin-top: 40px; } .sig td { width: 45%; padding-top: 36px; border-top: 1px solid #9ca3af; font-size: 10px; vertical-align: top; }
 .foot { margin-top: 30px; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style></head><body>
@if($void)<div class="void">VOID</div>@endif
<table><tr>
 <td style="vertical-align:top">@if($logo)<img src="{{ $logo }}" alt="" style="height:40px"><br>@endif
  <strong>{{ $issuer['name'] ?? '' }}</strong>@if(!empty($issuer['ssm_no'])) <span class="muted">({{ $issuer['ssm_no'] }})</span>@endif<br>
  <span class="muted">{{ $addr($issuer['address'] ?? null) }}</span></td>
 <td style="text-align:right;vertical-align:top"><h1>{{ $settings['title'] ?? strtoupper($doc['label']) }}</h1>
  <strong>{{ $doc['number'] }}</strong><br>Date: {{ $doc['issue_date'] }}</td>
</tr></table>
<div class="rule"></div>
<p><span class="muted">{{ $doc['counterparty_label'] }}:</span><br><strong>{{ $counterparty['name'] ?? '' }}</strong>
 @if(!empty($counterparty['position']))<br>{{ $counterparty['position'] }}@endif
 @if(!empty($counterparty['staff_no']))<br><span class="muted">Staff no. {{ $counterparty['staff_no'] }}</span>@endif
 @if(!empty($counterparty['address']))<br>{{ $addr($counterparty['address']) }}@endif</p>
@if($effective_date)<p><span class="muted">Effective date:</span> {{ $effective_date }}</p>@endif
<p class="subject">{{ $subject }}</p>
<div>{!! nl2br(e((string) $body)) !!}</div>
<table class="sig"><tr>
 <td>{{ $issuer['signatory_name'] ?? '' }}<br>{{ $issuer['signatory_title'] ?? 'Authorised signatory' }}<br>for {{ $issuer['name'] ?? '' }}</td>
 <td style="width:10%;border:none"></td>
 @if($doc['label'] === 'Appointment letter')<td>Accepted by: {{ $counterparty['name'] ?? '' }}<br>Date:</td>@else<td style="border:none"></td>@endif
</tr></table>
<div class="foot">{{ $settings['footer_text'] ?? '' }}<br>Issued document {{ $doc['number'] }} · template {{ $template }} · stored unaltered.@if($void) VOIDED: {{ $doc['void_reason'] }}@endif</div>
</body></html>
