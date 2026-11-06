{{-- CSR annual report (M08 output). Values are escaped; in-kind is reported separately from cash (M08-03). --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CSR annual report {{ $report['year'] }}</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; } td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; } .muted { color: #6b7280; } .kpis td { border: 1px solid #e5e7eb; text-align: center; font-size: 12px; }
    .kpis strong { display: block; font-size: 16px; }
</style>
</head>
<body>
    <h1>{{ $company }} — CSR annual report {{ $report['year'] }}</h1>
    <p class="muted">Generated {{ now()->format('d M Y') }}. Cash spend comes from finance records linked to each programme; in-kind values are estimates and are not part of cash.</p>
    <table class="kpis"><tr>
        <td><strong>{{ $report['totals']['programmes'] }}</strong>programmes</td>
        <td><strong>{{ $report['totals']['reached'] }}</strong>beneficiaries reached</td>
        <td><strong>{{ $report['totals']['hours'] }}</strong>volunteer hours</td>
        <td><strong>RM {{ \App\Kernel\Money\Money::format($report['totals']['cash']) }}</strong>cash spend</td>
        <td><strong>RM {{ \App\Kernel\Money\Money::format($report['totals']['in_kind']) }}</strong>in-kind (estimated)</td>
    </tr></table>
    @foreach ($report['programmes'] as $p)
        <h2>{{ $p['programme_no'] }} · {{ $p['title'] }} <span class="muted">({{ $p['date'] }})</span></h2>
        <table>
            <tr><th style="width:25%">Objectives</th><td>{{ $p['objectives'] ?? '—' }}</td></tr>
            <tr><th>Partner / sponsor</th><td>{{ $p['partner'] ?? '—' }} / {{ $p['sponsor'] ?? '—' }}</td></tr>
            <tr><th>Venue</th><td>{{ $p['venue'] ?? '—' }}</td></tr>
            <tr><th>Beneficiaries</th><td>{{ $p['beneficiary_group'] ?? '—' }} — target {{ $p['target'] }}, reached {{ $p['actual'] ?? '—' }}</td></tr>
            <tr><th>Volunteer hours</th><td>{{ $p['volunteer_hours'] }}</td></tr>
            <tr><th>Cash spend</th><td>RM {{ \App\Kernel\Money\Money::format($p['cash']) }} (budget RM {{ \App\Kernel\Money\Money::format($p['budget']) }})</td></tr>
            <tr><th>In-kind (estimated)</th><td>RM {{ \App\Kernel\Money\Money::format($p['in_kind']) }}</td></tr>
            <tr><th>Outcome</th><td>{{ $p['outcome'] ?? '—' }}</td></tr>
        </table>
    @endforeach
    @if (empty($report['programmes']))
        <p>No CSR programmes recorded for {{ $report['year'] }}.</p>
    @endif
</body>
</html>
