<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Integration;
use App\Models\ListingHistory;
use App\Services\MarketplaceListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ListingSyncService;
use App\Services\QualityAuditService;
use Inertia\Inertia;

class MarketplaceListingController extends Controller
{
    protected MarketplaceListingService $service;
    protected ListingSyncService $syncService;
    protected QualityAuditService $auditService;

    public function __construct(
        MarketplaceListingService $service,
        ListingSyncService $syncService,
        QualityAuditService $auditService
    ) {
        $this->service = $service;
        $this->syncService = $syncService;
        $this->auditService = $auditService;
    }

    public function index(Request $request)
    {
        $companyId = Auth::user()->company_id;
        
        $listings = Product::where('company_id', $companyId)
            ->whereNotNull('json_data->ml_item_id')
            ->when($request->search, function($query, $search) {
                $query->where('title', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(20)
            ->through(function ($product) {
                $product->quality_metrics = $this->auditService->audit($product);
                return $product;
            });

        $credentials = Integration::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('platform', '!=', 'bling')
            ->get();

        return Inertia::render('Marketplace/Listings', [
            'listings' => $listings,
            'credentials' => $credentials,
            'filters' => $request->only('search'),
        ]);
    }

    public function bulkEditor()
    {
        $listings = Product::where('company_id', Auth::user()->company_id)
            ->whereNotNull('json_data->ml_item_id')
            ->get();

        return Inertia::render('Marketplace/BulkEditor', [
            'listings' => $listings
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'field' => 'required|string|in:price,stock,status,title,description,images,all_sync',
            'value' => 'nullable',
            'template_id' => 'nullable|exists:products,id'
        ]);

        $companyId = Auth::user()->company_id;

        // Se for uma ação de sincronização (Nivelamento)
        if (in_array($request->field, ['title', 'description', 'images', 'all_sync'])) {
            $templateId = $request->template_id;
            if (!$templateId) return redirect()->back()->with('error', 'Selecione um anúncio mestre para nivelar.');
            
            $count = $this->syncService->syncFieldFromTemplate($request->ids, $templateId, $request->field);
            return redirect()->back()->with('success', "Nivelamento concluído: {$count} anúncios atualizados.");
        }
        $products = Product::whereIn('id', $request->ids)->where('company_id', $companyId)->get();

        DB::transaction(function () use ($products, $request, $companyId) {
            foreach ($products as $product) {
                $oldValue = $product->{$request->field === 'price' ? 'sale_price' : ($request->field === 'stock' ? 'stock_quantity' : 'status')};
                
                // Lógica de ajuste (Ex: +10%)
                $newValue = $request->value;
                if (str_ends_with($request->value, '%')) {
                    $percent = (float)str_replace('%', '', $request->value);
                    $newValue = $oldValue * (1 + ($percent / 100));
                }

                // Log de Histórico para Rollback
                ListingHistory::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'marketplace_item_id' => $product->json_data['ml_item_id'] ?? 'N/D',
                    'field' => $request->field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'action_source' => 'bulk_edit'
                ]);

                // Atualiza o produto
                if ($request->field === 'price') $product->sale_price = $newValue;
                elseif ($request->field === 'stock') $product->stock_quantity = $newValue;
                else $product->status = $newValue;

                $product->save();
            }
        });

        return redirect()->back()->with('success', 'Atualização em massa concluída.');
    }

    public function history()
    {
        $history = ListingHistory::with('product')
            ->where('company_id', Auth::user()->company_id)
            ->latest()
            ->take(50)
            ->get();

        return response()->json($history);
    }

    public function rollback(Request $request)
    {
        $request->validate(['history_id' => 'required|exists:listing_histories,id']);
        
        $log = ListingHistory::where('company_id', Auth::user()->company_id)->findOrFail($request->history_id);
        
        DB::transaction(function () use ($log) {
            $product = $log->product;
            
            // Reverte o valor
            if ($log->field === 'price') $product->sale_price = $log->old_value;
            elseif ($log->field === 'stock') $product->stock_quantity = $log->old_value;
            else $product->status = $log->old_value;

            $product->save();
            
            // Opcional: Marcar como revertido ou deletar o log
            $log->delete();
        });

        return redirect()->back()->with('success', 'Alteração revertida com sucesso.');
    }

    /**
     * "Sincronizar Tudo" — sem conta selecionada (credential_id nulo), sincroniza
     * TODAS as integrações ativas da empresa (o próprio texto do botão diz
     * "Tudo"); com uma conta selecionada, sincroniza só ela.
     */
    public function sync(Request $request)
    {
        $request->validate([
            'credential_id' => 'nullable|exists:integrations,id',
        ]);

        $companyId = Auth::user()->company_id;

        $credentials = $request->credential_id
            ? collect([Integration::where('company_id', $companyId)->findOrFail($request->credential_id)])
            : Integration::where('company_id', $companyId)->where('is_active', true)->get();

        if ($credentials->isEmpty()) {
            return redirect()->back()->with('error', 'Nenhuma integração ativa encontrada para sincronizar.');
        }

        $imported = 0;
        $updated = 0;
        $errors = [];
        $anyOk = false;

        foreach ($credentials as $credential) {
            $result = $this->service->syncListings($credential);
            $anyOk = $anyOk || $result['ok'];
            $imported += $result['imported'];
            $updated += $result['updated'];
            if (!$result['ok']) {
                $errors[] = ($credential->account_nickname ?: $credential->platform) . ': ' . $result['message'];
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        $errorSuffix = empty($errors) ? '' : ', ' . count($errors) . ' erro(s)';

        if ($credentials->count() > 1) {
            $message = sprintf('Sincronização de %d conta(s): %d criado(s), %d atualizado(s)%s.',
                $credentials->count(), $imported, $updated, $errorSuffix);
        } elseif (!$anyOk) {
            $message = $errors[0] ?? 'Falha ao sincronizar.';
        } else {
            $message = sprintf('Sincronização concluída: %d criado(s), %d atualizado(s)%s.', $imported, $updated, $errorSuffix);
        }

        return redirect()->back()->with($anyOk ? 'success' : 'error', $message);
    }
}
