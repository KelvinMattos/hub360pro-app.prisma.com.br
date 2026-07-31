<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\Schema;

/**
 * Identifica/casa um cliente pelo CPF (doc_number, só dígitos) — a chave que
 * une o mesmo comprador entre canais diferentes (Mercado Livre, Shopee,
 * Magazord, Netshoes). Sem CPF, cai pra (external_id + canal), que só
 * cruza pedidos do MESMO canal, já que sem documento não dá pra saber que é
 * a mesma pessoa comprando por outro lugar.
 *
 * Extraído da lógica que já existia (só pra ML/Shopee) em
 * App\Console\Commands\SyncOrdersCommand::saveOrders(), pra ser reaproveitada
 * pelos importadores Magazord/Netshoes, que hoje nunca tocam a tabela
 * `customers` nem preenchem `orders.customer_id`.
 */
class CustomerIdentityService
{
    public static function normalizeDoc(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = preg_replace('/[^0-9]/', '', $raw);
        return $clean !== '' ? $clean : null;
    }

    /**
     * Expressão SQL do CPF/CNPJ normalizado (só dígitos), dado o nome das
     * colunas onde ele pode estar gravado — nunca as duas ao mesmo tempo.
     * Usada por CustomerController e SalesController pra não duplicar de
     * novo a mesma cadeia de REPLACE().
     */
    public static function sqlDocExpr(string $billingCol = 'billing_doc_number', string $docCol = 'customer_doc'): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($billingCol, $docCol), '.', ''), '-', ''), '/', ''), ' ', '')";
    }

    /**
     * Acha o cliente da empresa pelo CPF; sem isso, tenta por
     * (external_id + canal). Sem nenhum identificador confiável, retorna
     * null — nunca inventa um cliente sem nada que o identifique.
     *
     * $data aceita: doc_number, name, email, phone, city, state,
     * external_id, origin_channel.
     */
    public function findOrCreate(int $companyId, array $data): ?Customer
    {
        if (!Schema::hasTable('customers') || !Schema::hasColumn('customers', 'doc_number')) {
            return null;
        }

        $doc = self::normalizeDoc($data['doc_number'] ?? null);
        $externalId = $data['external_id'] ?? null;
        $channel = $data['origin_channel'] ?? null;

        if (!$doc && !$externalId) {
            return null;
        }

        $customer = null;
        if ($doc) {
            $customer = Customer::where('company_id', $companyId)->where('doc_number', $doc)->first();
        }
        if (!$customer && $externalId) {
            $customer = Customer::where('company_id', $companyId)
                ->where('external_id', $externalId)
                ->where('origin_channel', $channel)
                ->first();
        }

        $attrs = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($customer) {
            if (empty($customer->origin_channel) && $channel) {
                $attrs['origin_channel'] = $channel;
            }
            if (empty($customer->doc_number) && $doc) {
                $attrs['doc_number'] = $doc;
                $attrs['doc_type'] = $data['doc_type'] ?? 'CPF';
            }
            if (!empty($attrs)) {
                $customer->update($attrs);
            }
            return $customer;
        }

        return Customer::create(array_merge($attrs, [
            'company_id' => $companyId,
            'name' => $attrs['name'] ?? 'Cliente',
            'doc_number' => $doc,
            'doc_type' => $doc ? ($data['doc_type'] ?? 'CPF') : null,
            'external_id' => $externalId,
            'origin_channel' => $channel,
        ]));
    }
}
