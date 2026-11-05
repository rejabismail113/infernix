{{-- Infernix standard document layout v1 (quotation / invoice / credit note / receipt / payment voucher).
     Rendered ONLY from the snapshots stored on the document at issue time (M03-07). DomPDF-safe CSS (tables, no flex).
     @see SRS M03-01, M03-03, M03-04, M03-06, M03-07, M03-08 --}}
@php($m = fn ($v) => \App\Kernel\Money\Money::format((string) $v))
@php($accent = $settings['accent_color'] ?? '#ea580c')
@php($addr = fn ($a) => is_array($a) ? implode(', ', array_filter([$a['line1'] ?? null, $a['line2'] ?? null, trim(($a['postcode'] ?? '').' '.($a['city'] ?? '')), $a['state'] ?? null, $a['country'] ?? null], fn ($v) => is_string($v) && trim($v) !== '')) : (string) $a)
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $doc['number'] }}</title>
<style>
 @page { margin: 14mm 12mm; }
 body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
 .accent { color: {{ $accent }}; } .muted { color: #6b7280; } .r { text-align: right; } .c { text-align: center; }
 h1 { font-size: 20px; margin: 0 0 4px; letter-spacing: 1px; color: {{ $accent }}; }
 table { width: 100%; border-collapse: collapse; }
 .head td { vertical-align: top; }
 .box { border: 1px solid #e5e7eb; padding: 8px; }
 .lines { margin-top: 14px; } .lines th { background: {{ $accent }}; color: #fff; padding: 6px; font-weight: bold; text-align: left; }
 .lines td { border-bottom: 1px solid #e5e7eb; padding: 6px; vertical-align: top; }
 .totals td { padding: 4px 6px; } .totals .grand td { border-top: 2px solid {{ $accent }}; font-weight: bold; font-size: 12px; }
 .sig { margin-top: 36px; } .sig td { width: 33%; padding-top: 34px; border-top: 1px solid #9ca3af; font-size: 9.5px; }
 .void { position: fixed; top: 38%; left: 8%; font-size: 110px; color: rgba(220, 38, 38, 0.18); transform: rotate(-30deg); font-weight: bold; letter-spacing: 12px; }
 .foot { margin-top: 24px; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style></head><body>
@if($void)<div class="void">VOID</div>@endif
<table class="head"><tr>
 <td style="width:55%">
  @if($logo)<img src="{{ $logo }}" alt="" style="height:42px;margin-bottom:6px"><br>@endif
  <strong style="font-size:13px">{{ $issuer['name'] ?? '' }}</strong>@if(!empty($issuer['ssm_no'])) <span class="muted">({{ $issuer['ssm_no'] }})</span>@endif<br>
  <span class="muted">{{ $addr($issuer['address'] ?? null) }}</span><br>
  <span class="muted">{{ implode(' · ', array_filter([$issuer['phone'] ?? null, $issuer['email'] ?? null])) }}</span>
 </td>
 <td class="r">
  <h1>{{ $settings['title'] ?? strtoupper($doc['label']) }}</h1>
  <strong style="font-size:12px">{{ $doc['number'] }}</strong>@if($doc['revision'] > 0) <span class="muted">rev {{ $doc['revision'] }}</span>@endif<br>
  Date: {{ $doc['issue_date'] }}<br>
  @if($doc['due_date'])Due: {{ $doc['due_date'] }}<br>@endif
  @if($doc['valid_until'])Valid until: {{ $doc['valid_until'] }}<br>@endif
  @foreach($doc['references'] as $label => $ref)<span class="muted">{{ $label }}:</span> {{ $ref }}<br>@endforeach
 </td>
</tr></table>

<table style="margin-top:12px"><tr>
 <td class="box" style="width:55%">
  <span class="muted">{{ $doc['counterparty_label'] }}</span><br>
  <strong>{{ $counterparty['name'] ?? '' }}</strong><br>
  @if(!empty($counterparty['registration_no']))<span class="muted">Reg. no.</span> {{ $counterparty['registration_no'] }}<br>@endif
  @if(!empty($counterparty['address'])){{ $addr($counterparty['address']) }}<br>@endif
  @if(!empty($counterparty['contact_person'])){{ $counterparty['contact_person'] }} @endif{{ $counterparty['email'] ?? '' }}
 </td>
 <td style="width:3%"></td>
 <td class="box">
  @foreach($doc['facts'] as $label => $value)<span class="muted">{{ $label }}:</span> {{ $value }}<br>@endforeach
  @if($doc['title'])<strong>{{ $doc['title'] }}</strong>@endif
 </td>
</tr></table>

@if($detailed)
<table class="lines"><thead><tr>
 <th style="width:4%">#</th><th>Description</th><th class="r" style="width:8%">Qty</th><th style="width:7%">Unit</th>
 <th class="r" style="width:12%">Unit price</th><th class="r" style="width:10%">Discount</th><th class="r" style="width:7%">Tax</th><th class="r" style="width:13%">Amount (RM)</th>
</tr></thead><tbody>
 @foreach($lines as $l)
 <tr><td>{{ $l['line_no'] }}</td><td>{!! nl2br(e($l['description'])) !!}</td><td class="r">{{ rtrim(rtrim($l['quantity'], '0'), '.') }}</td><td>{{ $l['unit'] }}</td>
  <td class="r">{{ $m($l['unit_price']) }}</td><td class="r">{{ $l['discount'] !== '0.00' ? $m($l['discount']) : '' }}</td>
  <td class="r">{{ $l['tax_rate'] !== '0.0000' ? rtrim(rtrim($l['tax_rate'], '0'), '.').'%' : '' }}</td><td class="r">{{ $m($l['net']) }}</td></tr>
 @endforeach
</tbody></table>
<table class="totals" style="width:42%;margin-left:58%;margin-top:6px">
 <tr><td>Subtotal</td><td class="r">{{ $m($totals['subtotal']) }}</td></tr>
 @if($totals['tax'] !== '0.00')<tr><td>Tax</td><td class="r">{{ $m($totals['tax']) }}</td></tr>@endif
 <tr class="grand"><td>Total (MYR)</td><td class="r">{{ $m($totals['total']) }}</td></tr>
</table>
@else
<table class="lines"><thead><tr><th style="width:25%">Reference</th><th>Description</th><th class="r" style="width:18%">Amount (RM)</th></tr></thead><tbody>
 @foreach($lines as $l)<tr><td>{{ $l['ref'] }}</td><td>{{ $l['description'] }}</td><td class="r">{{ $m($l['amount']) }}</td></tr>@endforeach
</tbody></table>
<table class="totals" style="width:42%;margin-left:58%;margin-top:6px"><tr class="grand"><td>Total (MYR)</td><td class="r">{{ $m($totals['total']) }}</td></tr></table>
@endif

@if($doc['terms'])<div style="margin-top:14px"><strong>Terms &amp; conditions</strong><br>{!! nl2br(e($doc['terms'])) !!}</div>@endif
@if(($settings['show_bank_details'] ?? false) && !empty($issuer['bank']))
<div style="margin-top:10px" class="box"><strong>Payment details</strong><br>
 {{ $issuer['bank']['bank_name'] }} · {{ $issuer['bank']['account_name'] }} · Account no. {{ $issuer['bank']['account_no'] }}<br>
 <span class="muted">Please quote {{ $doc['number'] }} as the payment reference.</span></div>
@endif

<table class="sig"><tr>
 @foreach($signatures as $s)<td>{{ $s['label'] }}<br><strong>{{ $s['name'] }}</strong>@if(!empty($s['title'])), {{ $s['title'] }}@endif</td><td style="width:3%;border:none"></td>@endforeach
</tr></table>

<div class="foot">{{ $settings['footer_text'] ?? '' }}<br>Issued document {{ $doc['number'] }} · template {{ $template }} · generated by Infernix One and stored unaltered.@if($void) VOIDED: {{ $doc['void_reason'] }}@endif</div>
</body></html>
