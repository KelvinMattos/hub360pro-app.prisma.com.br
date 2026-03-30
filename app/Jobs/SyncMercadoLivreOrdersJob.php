<?php

namespace App\Jobs;

use App\Services\MercadoLivreApiService;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncMercadoLivreOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de tentativas do Job em caso de falha.
     */
    public $tries = 3;

    /**
     * Tempo de espera entre tentativas (segundos).
     */
    public $backoff = [60, 300, 600];

    protected int $companyId;
    protected ?string $dateFrom;
    protected ?string $dateTo;

    /**
     * Create a new job instance.
     */
    public function __construct(int $companyId, ?string $dateFrom = null, ?string $dateTo = null)
    {
        $this->companyId = $companyId;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
    }

    /**
     * Execute the job.
     */
    public function handle(MercadoLivreApiService $service): void
    {
        // Precisamos instanciar o service manualmente se o companyId for passado no construct
        // Ou podemos injetar uma Factory. Para este cenário, vamos usar o ID.
        $service = (new MercadoLivreApiService())->forCompany($this->companyId);

        Log::info("Iniciando Sincronização de Pedidos ML para Company: {$this->companyId}");

        $offset = 0;
        $limit = 50;
        $hasMore = true;

        while ($hasMore) {
            $params = [
                'offset' => $offset,
                'limit' => $limit,
                'sort' => 'date_desc',
            ];

            if ($this->dateFrom) {
                $params['order.date_created.from'] = $this->dateFrom;
            }
            if ($this->dateTo) {
                $params['order.date_created.to'] = $this->dateTo;
            }

            $response = $service->get('/orders/search', $params);

            if ($response->failed()) {
                Log::error("Erro ao buscar pedidos ML: " . $response->body());
                throw new \Exception("Erro na API do Mercado Livre durante extração massiva.");
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            foreach ($results as $orderData) {
                $this->processOrder($orderData);
            }

            $offset += $limit;

            // Verifica se chegamos ao fim ou ao limite de 1000 que exige scroll
            if (empty($results) || $offset >= ($data['paging']['total'] ?? 0) || $offset >= 1000) {
                $hasMore = false;
            }
        }

        Log::info("Sincronização de Pedidos ML concluída para Company: {$this->companyId}");
    }

    /**
     * Processa e salva o pedido no banco de dados.
     */
    protected function processOrder(array $orderData): void
    {
        // 1. Cálculo de Taxas e Comissões
        $mlFeeAmount = 0;
        foreach ($orderData['order_items'] ?? [] as $item) {
            $mlFeeAmount += ($item['sale_fee'] ?? 0);
        }

        // 2. Dados de Frete
        $shippingCost = $orderData['shipping']['cost'] ?? 0;
        
        // 3. Consolidação de Pagamentos
        $totalPaid = 0;
        foreach ($orderData['payments'] ?? [] as $payment) {
            if ($payment['status'] === 'approved') {
                $totalPaid += $payment['total_paid_amount'];
            }
        }

        // Recuperar Integration associada a essa venda, se possível (opcional, pegando a primeira ativa)
        $integration = \App\Models\Integration::where('company_id', $this->companyId)
            ->where('platform', 'mercadolibre')->first();

        // 4. Persistência de Dados
        $order = Order::updateOrCreate(
            [
                'company_id' => $this->companyId,
                'external_id' => (string) $orderData['id']
            ],
            [
                'integration_id' => $integration ? $integration->id : null,
                'status' => $orderData['status'],
                'buyer_nickname' => $orderData['buyer']['nickname'] ?? null,
                'customer_name' => $orderData['buyer']['nickname'] ?? 'Cliente Desconhecido',
                'customer_doc' => $orderData['buyer']['billing_info']['doc_number'] ?? null,
                'billing_doc_type' => $orderData['buyer']['billing_info']['doc_type'] ?? null,
                'billing_doc_number' => $orderData['buyer']['billing_info']['doc_number'] ?? null,
                'total_amount' => $orderData['total_amount'],
                'total_paid_amount' => $totalPaid,
                'cost_tax_platform' => $mlFeeAmount,
                'cost_shipping_seller' => $shippingCost,
                'payment_status' => $orderData['payments'][0]['status'] ?? $orderData['status'], 
                'json_payments' => $orderData['payments'] ?? [],
                'json_order' => $orderData,
                'date_created' => Carbon::parse($orderData['date_created']),
            ]
        );

        // 5. Salvar Itens do Pedido separadamente
        foreach ($orderData['order_items'] ?? [] as $itemData) {
            \App\Models\OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'external_id' => $itemData['item']['id'] ?? uniqid()],
                [
                    'sku' => $itemData['item']['seller_sku'] ?? null,
                    'title' => $itemData['item']['title'] ?? 'Item',
                    'quantity' => $itemData['quantity'] ?? 1,
                    'unit_price' => $itemData['unit_price'] ?? 0,
                    'unit_cost' => 0
                ]
            );
        }
    }
}
