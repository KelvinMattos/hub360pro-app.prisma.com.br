<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 11px; }
    h1 { font-size: 16px; margin: 0 0 4px; }
    .meta { color: #64748b; font-size: 10px; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
    th { background: #0f172a; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
    tr:nth-child(even) td { background: #f8fafc; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">Gerado em {{ $generatedAt }} — PrismaHUB 360 PRO</p>
    <table>
        <thead>
            <tr>
                @foreach ($header as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td @class(['num' => is_numeric($cell)])>
                            @if (is_numeric($cell))
                                {{ number_format((float) $cell, 2, ',', '.') }}
                            @else
                                {{ $cell ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($header) }}">Sem dados para o período selecionado.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
