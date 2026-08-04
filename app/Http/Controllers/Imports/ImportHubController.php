<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\SalesChannelAccount;
use App\Services\Imports\ImportDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Central de Importações: uma única caixa de upload que detecta o tipo do
 * arquivo (pelo cabeçalho ou, no caso do Diário de Vendas por Canal, pelos
 * nomes das abas) e mostra pra qual tela ele vai, sem o usuário precisar
 * escolher entre os ~20 tipos espalhados em 6 controllers.
 *
 * Este controller SÓ detecta e mostra a confirmação — nunca grava nada no
 * banco. A gravação de fato continua acontecendo na tela/rota original de
 * cada tipo (magazord.import, order-channel.import, etc.), reaproveitada
 * tal como está hoje: zero duplicação de lógica de importação, zero risco
 * de a Central divergir do parser já testado de cada tela.
 */
class ImportHubController extends Controller
{
    public function __construct(private ImportDetectionService $detector)
    {
    }

    public function show()
    {
        return Inertia::render('Imports/Hub', [
            'catalog' => $this->detector->presentCatalog(),
        ]);
    }

    /** Lê só o cabeçalho do arquivo enviado e devolve a detecção — não grava nada. */
    public function detect(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
        ]);

        $path = $request->file('file')->getRealPath();
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());

        [$header, $sheetNames] = match ($ext) {
            'xlsx' => $this->sniffXlsx($path),
            'csv', 'txt' => [$this->sniffCsv($path), []],
            default => [[], []],
        };

        if (empty($header) && empty($sheetNames)) {
            return response()->json([
                'status' => 'unknown',
                'match' => null,
                'candidates' => [],
                'message' => 'Não consegui ler o cabeçalho desse arquivo. Envie um .csv, .txt ou .xlsx com a linha de cabeçalho na primeira linha.',
            ]);
        }

        $result = $this->detector->detect($header, $sheetNames);

        return response()->json($this->attachAccountOptions($result));
    }

    /** Lê só a 1ª linha (cabeçalho) de um CSV/TXT, sem carregar o arquivo inteiro. */
    private function sniffCsv(string $path): array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            return [];
        }
        $first = fgets($fh);
        $delim = (substr_count((string) $first, ';') >= substr_count((string) $first, ',')) ? ';' : ',';
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim) ?: [];
        fclose($fh);

        return array_map(fn ($h) => trim($this->toUtf8((string) $h)), $header);
    }

    /** Lê a 1ª linha da 1ª aba (cabeçalho) e os nomes de TODAS as abas, sem ler o resto das linhas. */
    private function sniffXlsx(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);
        $header = [];
        $sheetNames = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
                if (empty($header)) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $header = array_map(fn ($c) => trim((string) $c), $row->toArray());
                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return [$header, $sheetNames];
    }

    private function toUtf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    /** Anexa a lista de contas disponíveis quando o tipo detectado exige escolher uma. */
    private function attachAccountOptions(array $result): array
    {
        $companyId = Auth::user()->company_id;

        if (!empty($result['match']) && ($result['match']['needs_account'] ?? false)) {
            $result['match']['accounts'] = $this->accountsFor($result['match'], $companyId);
        }

        foreach ($result['candidates'] as &$candidate) {
            if ($candidate['needs_account'] ?? false) {
                $candidate['accounts'] = $this->accountsFor($candidate, $companyId);
            }
        }
        unset($candidate);

        return $result;
    }

    private function accountsFor(array $entry, int $companyId): array
    {
        return match ($entry['account_source'] ?? null) {
            'sales_channel' => SalesChannelAccount::where('company_id', $companyId)
                ->where('channel', $entry['account_channel'])->where('is_active', true)
                ->orderBy('label')->get(['id', 'label'])->toArray(),
            'ads' => AdAccount::where('company_id', $companyId)
                ->where('platform', $entry['account_channel'])->where('is_active', true)
                ->orderBy('label')->get(['id', 'label'])->toArray(),
            default => [],
        };
    }
}
