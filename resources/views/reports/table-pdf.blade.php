{{-- Generic report table for PDF exports (Kernel\Reports\PdfTable). Values are already secret-scrubbed. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { size: A4 landscape; margin: 12mm 10mm 14mm 10mm; }
    body { font-family: 'Poppins', 'Helvetica Neue', Arial, sans-serif; font-size: 9px; color: #111827; margin: 0; }
    header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #D45F00; padding-bottom: 6px; margin-bottom: 10px; }
    h1 { font-size: 16px; margin: 0; }
    .brand { font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: #B84A00; }
    .meta { font-size: 8.5px; color: #6B7280; text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #FFF4EB; color: #7C2D00; text-align: left; font-weight: 600; padding: 5px 6px; border-bottom: 1px solid #F3C9A6; }
    td { padding: 4px 6px; border-bottom: 1px solid #EEF0F3; vertical-align: top; word-break: break-word; }
    tr:nth-child(even) td { background: #FAFAFB; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .empty { padding: 16px; text-align: center; color: #6B7280; }
    footer { margin-top: 8px; font-size: 8px; color: #9CA3AF; }
</style>
</head>
<body>
<header>
    <div>
        <div class="brand">INFERNIX WORKSPACE</div>
        <h1>{{ $title }}</h1>
    </div>
    <div class="meta">
        Generated {{ $generatedAt }}@if ($generatedBy) by {{ $generatedBy }}@endif<br>
        {{ count($rows) }} {{ count($rows) === 1 ? 'row' : 'rows' }}
    </div>
</header>
<table>
    <thead>
        <tr>
            @foreach ($headers as $i => $h)
                <th @class(['num' => isset($numeric[$i])])>{{ $h }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($row as $i => $cell)
                    <td @class(['num' => isset($numeric[$i])])>{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td class="empty" colspan="{{ max(1, count($headers)) }}">No records.</td></tr>
        @endforelse
    </tbody>
</table>
<footer>Confidential — exported from Infernix Workspace. Every export is recorded in the audit log.</footer>
</body>
</html>
