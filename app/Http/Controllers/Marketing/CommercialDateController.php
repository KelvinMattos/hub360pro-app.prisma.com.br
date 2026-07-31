<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Calendário de datas comerciais. `company_id` nulo = data padrão (seed),
 * visível pra toda empresa; preenchido = data própria (manual ou importada).
 *
 * Importação por CSV (";" ou "," como delimitador, tolerante a ISO-8859-1 —
 * mesmo padrão dos importadores Magazord/Netshoes): Data, Título, Categoria,
 * Recorrente.
 */
class CommercialDateController extends Controller
{
    public function index(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        $year = (int) $request->get('year', now()->year);

        $dates = $companyId
            ? DB::table('commercial_dates')
                ->where(function ($q) use ($companyId) {
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->orderBy('date')
                ->get()
            : collect();

        $rows = $dates->map(function ($d) use ($year) {
            $date = Carbon::parse($d->date);
            $display = $d->recurring_yearly ? $date->copy()->year($year) : $date;
            return [
                'id' => $d->id, 'title' => $d->title, 'category' => $d->category,
                'date' => $display->toDateString(), 'recurring_yearly' => (bool) $d->recurring_yearly,
                'source' => $d->source, 'is_global' => $d->company_id === null,
                'notes' => $d->notes,
            ];
        })->sortBy('date')->values();

        return Inertia::render('Marketing/Calendar/Index', [
            'dates' => $rows->all(),
            'year' => $year,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->with('error', 'Empresa não identificada.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'category' => ['nullable', 'string', 'max:40'],
            'recurring_yearly' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::table('commercial_dates')->insert([
            'company_id' => $companyId,
            'title' => $data['title'],
            'date' => $data['date'],
            'category' => $data['category'] ?? 'proprio',
            'recurring_yearly' => (bool) ($data['recurring_yearly'] ?? true),
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Data comercial adicionada.');
    }

    public function destroy(int $date)
    {
        $companyId = Auth::user()?->company_id;
        // Só apaga data própria da empresa — datas globais (seed) não podem ser removidas por aqui.
        $exists = DB::table('commercial_dates')->where('id', $date)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        DB::table('commercial_dates')->where('id', $date)->delete();

        return back()->with('success', 'Data removida.');
    }

    public function import(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->with('error', 'Empresa não identificada.');
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $path = $request->file('file')->getRealPath();
        $created = 0;
        $skipped = 0;
        $rows = 0;

        try {
            foreach ($this->readRows($path) as $row) {
                $rows++;
                $title = $this->col($row, ['Título', 'Titulo', 'Nome', 'Evento']);
                $dateRaw = $this->col($row, ['Data']);
                if ($title === null || $title === '' || $dateRaw === null || $dateRaw === '') {
                    $skipped++;
                    continue;
                }

                $date = $this->parseDate($dateRaw);
                if ($date === null) {
                    $skipped++;
                    continue;
                }

                $category = $this->col($row, ['Categoria']) ?: 'importado';
                $recurringRaw = mb_strtolower(trim((string) $this->col($row, ['Recorrente', 'Anual']) ?? ''));
                $recurring = !in_array($recurringRaw, ['nao', 'não', 'no', '0', 'false'], true);

                DB::table('commercial_dates')->insert([
                    'company_id' => $companyId,
                    'title' => $title,
                    'date' => $date,
                    'category' => $category,
                    'recurring_yearly' => $recurring,
                    'source' => 'import',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Falha ao importar: ' . $e->getMessage());
        }

        return back()->with('success', "Datas importadas: {$created} criadas, {$skipped} ignoradas (de {$rows} linhas).");
    }

    /** Leitura de CSV tolerante a ISO-8859-1, delimitador ";" ou ",". */
    private function readRows(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('não foi possível abrir o arquivo enviado.');
        }
        try {
            $firstLine = fgets($fh);
            rewind($fh);
            $delimiter = substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',') ? ';' : ',';

            $header = fgetcsv($fh, 0, $delimiter);
            if ($header === false) {
                return;
            }
            $header = array_map(fn ($h) => trim($this->toUtf8((string) $h)), $header);

            while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
                if ($data === [null] || $data === ['']) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $h) {
                    if ($h === '') continue;
                    $row[$h] = array_key_exists($i, $data) && $data[$i] !== null ? $this->toUtf8((string) $data[$i]) : null;
                }
                yield $row;
            }
        } finally {
            fclose($fh);
        }
    }

    private function toUtf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim($header)) === mb_strtolower($c)) {
                    return $value === null ? null : trim((string) $value);
                }
            }
        }
        return null;
    }

    private function parseDate(string $value): ?string
    {
        foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value))->toDateString();
            } catch (\Throwable $e) {
                // tenta próximo formato
            }
        }
        return null;
    }
}
