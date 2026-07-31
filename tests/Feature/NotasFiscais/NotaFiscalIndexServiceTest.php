<?php

namespace Tests\Feature\NotasFiscais;

use App\Models\NotaFiscalCompra;
use App\Services\NotasFiscais\NotaFiscalIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Módulo "Compras > Notas Fiscais de Compra": indexação de texto de PDFs
 * (smalot/pdfparser, puro PHP — sem depender de binário do sistema, já que o
 * cPanel não dá acesso a instalar pacotes como poppler-utils) e busca via
 * FULLTEXT nativo do MySQL (sem Scout/Meilisearch — nenhum serviço externo
 * novo pra manter no ar, mesmo espírito do resto do projeto).
 *
 * Os PDFs de teste são gerados byte-a-byte (sem biblioteca de escrita de PDF
 * disponível no ambiente) com um texto conhecido para validar a extração.
 */
class NotaFiscalIndexServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return base_path("tests/Fixtures/notas-fiscais/{$name}");
    }

    private function seedDisk(array $files): void
    {
        Storage::fake('notas_fiscais');
        foreach ($files as $path => $fixtureName) {
            Storage::disk('notas_fiscais')->put($path, file_get_contents($this->fixture($fixtureName)));
        }
    }

    public function test_indexa_pdf_novo_e_extrai_texto(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['fornecedor-acme/nota-teste.pdf' => 'nota-teste.pdf']);

        $result = app(NotaFiscalIndexService::class)->indexAll($companyId);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['indexed']);

        $nota = NotaFiscalCompra::where('company_id', $companyId)->first();
        $this->assertSame('indexed', $nota->status);
        $this->assertSame(1, $nota->pages_count);
        $this->assertNotNull($nota->hash);

        $pagina = $nota->paginas()->first();
        $this->assertStringContainsString('EAN 7891234567890', $pagina->content);
        $this->assertStringContainsString('ABC-123', $pagina->content);
    }

    /**
     * O índice FULLTEXT do InnoDB só enxerga linhas depois do COMMIT — dentro
     * da transação que o RefreshDatabase mantém aberta por teste, a busca
     * sempre retornaria 0 (confirmado isoladamente via tinker). Por isso este
     * teste comita de propósito antes de buscar; o RefreshDatabase detecta que
     * a conexão saiu da transação e força um migrate:fresh no próximo teste,
     * então não vaza dado entre testes.
     */
    public function test_busca_full_text_encontra_por_ean_e_por_codigo(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk([
            'nota-a.pdf' => 'nota-teste.pdf',
            'nota-b.pdf' => 'nota-fornecedor-beta.pdf',
        ]);
        app(NotaFiscalIndexService::class)->indexAll($companyId);
        DB::commit();

        $comEan = DB::table('nota_fiscal_paginas')->whereFullText('content', '7891234567890')->count();
        $comCodigo = DB::table('nota_fiscal_paginas')->whereFullText('content', 'XYZ')->count();

        $this->assertSame(1, $comEan);
        $this->assertSame(1, $comCodigo);
    }

    public function test_reindexacao_sem_force_pula_pdf_sem_mudanca(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['nota.pdf' => 'nota-teste.pdf']);
        $service = app(NotaFiscalIndexService::class);

        $first = $service->indexAll($companyId);
        $second = $service->indexAll($companyId);

        $this->assertSame(1, $first['indexed']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['indexed']);
        $this->assertSame(1, NotaFiscalCompra::where('company_id', $companyId)->count());
    }

    public function test_force_reindexa_mesmo_sem_mudanca(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['nota.pdf' => 'nota-teste.pdf']);
        $service = app(NotaFiscalIndexService::class);

        $service->indexAll($companyId);
        $result = $service->indexAll($companyId, force: true);

        $this->assertSame(1, $result['indexed']);
        $this->assertSame(0, $result['skipped']);
    }

    /** PDF sem texto extraível (sem camada de texto) é sinalizado, não afirmado como indexado com sucesso. */
    public function test_pdf_sem_texto_e_sinalizado_como_provavel_scan(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['nota-scan.pdf' => 'nota-escaneada.pdf']);

        $result = app(NotaFiscalIndexService::class)->indexAll($companyId);

        $nota = NotaFiscalCompra::where('company_id', $companyId)->first();
        $this->assertSame('failed', $nota->status);
        $this->assertStringContainsString('escaneado', $nota->error);
        $this->assertSame(1, $result['failed']);
    }

    public function test_arquivo_removido_do_disco_vira_orfao(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['nota.pdf' => 'nota-teste.pdf']);
        $service = app(NotaFiscalIndexService::class);
        $service->indexAll($companyId);

        Storage::disk('notas_fiscais')->delete('nota.pdf');
        $service->indexAll($companyId);

        $nota = NotaFiscalCompra::where('company_id', $companyId)->first();
        $this->assertSame('orphaned', $nota->status);
    }

    public function test_pdf_corrompido_nao_derruba_o_lote(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('notas_fiscais');
        Storage::disk('notas_fiscais')->put('corrompido.pdf', 'isso nao e um pdf valido');
        Storage::disk('notas_fiscais')->put('valido.pdf', file_get_contents($this->fixture('nota-teste.pdf')));

        $result = app(NotaFiscalIndexService::class)->indexAll($companyId);

        $this->assertSame(1, $result['indexed']);
        $this->assertSame(1, $result['failed']);

        $corrompido = NotaFiscalCompra::where('company_id', $companyId)->where('path', 'corrompido.pdf')->first();
        $this->assertSame('failed', $corrompido->status);
        $this->assertNotNull($corrompido->error);
    }

    /**
     * Incidente real: em produção, o Flysystem local tenta CRIAR a raiz do
     * disco assim que é resolvido, e derrubava com uma exceção crua
     * ("Unable to create a directory") em vez de um erro tratável — o
     * `indexAll()` nunca chegava a rodar. Reproduzido aqui apontando a raiz
     * pra um caminho ocupado por um arquivo comum (mkdir falha mesmo como
     * root, ao contrário de um diretório simplesmente ausente).
     */
    public function test_pasta_inacessivel_retorna_erro_tratavel_em_vez_de_estourar(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $arquivoNoLugarDaPasta = tempnam(sys_get_temp_dir(), 'nota-fiscal-root-');
        config(['filesystems.disks.notas_fiscais.root' => $arquivoNoLugarDaPasta]);

        $result = app(NotaFiscalIndexService::class)->indexAll($companyId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('inacessível', $result['error']);

        unlink($arquivoNoLugarDaPasta);
    }

    public function test_isola_por_empresa(): void
    {
        $companyA = DB::table('companies')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $companyB = DB::table('companies')->insertGetId(['name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedDisk(['nota.pdf' => 'nota-teste.pdf']);

        app(NotaFiscalIndexService::class)->indexAll($companyA);

        $this->assertSame(1, NotaFiscalCompra::where('company_id', $companyA)->count());
        $this->assertSame(0, NotaFiscalCompra::where('company_id', $companyB)->count());
    }
}
