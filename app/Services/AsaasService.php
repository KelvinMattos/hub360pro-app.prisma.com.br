<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class AsaasService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('asaas.api_key');
        $this->baseUrl = config('asaas.base_url');
    }

    /**
     * Criar um novo cliente no Asaas
     */
    public function createCustomer(array $data)
    {
        return $this->request('POST', '/customers', $data);
    }

    /**
     * Gerar uma nova cobrança (Boleto/Pix/Cartão)
     */
    public function createPayment(array $data)
    {
        return $this->request('POST', '/payments', $data);
    }

    /**
     * Buscar pagamentos
     */
    public function getPayments(array $filters = [])
    {
        return $this->request('GET', '/payments', $filters);
    }

    /**
     * Método base para requisições HTTP
     */
    protected function request(string $method, string $endpoint, array $data = [])
    {
        $response = Http::withHeaders([
            'access_token' => $this->apiKey,
        ])->send($method, $this->baseUrl . $endpoint, [
            'json' => $method !== 'GET' ? $data : null,
            'query' => $method === 'GET' ? $data : null,
        ]);

        if ($response->failed()) {
            throw new Exception("Erro Asaas API: " . $response->body());
        }

        return $response->json();
    }
}