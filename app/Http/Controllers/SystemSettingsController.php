<?php

namespace App\Http\Controllers;

use App\Services\CatalogResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Configurações do Sistema — inclui a "Zona de Perigo" com a limpeza de
 * emergência do catálogo/vendas.
 */
class SystemSettingsController extends Controller
{
    /** Frase que o usuário precisa digitar para confirmar a limpeza. */
    private const CONFIRM_PHRASE = 'LIMPAR TUDO';

    public function __construct(private CatalogResetService $reset)
    {
    }

    public function index()
    {
        return Inertia::render('Settings/System', [
            'preview' => $this->reset->preview(),
            'confirmPhrase' => self::CONFIRM_PHRASE,
        ]);
    }

    public function resetCatalog(Request $request)
    {
        $data = $request->validate([
            'confirm' => 'required|string',
            'tables' => 'required|array|min:1',
            'tables.*' => ['string', Rule::in($this->reset->tables)],
        ], [
            'tables.required' => 'Selecione pelo menos um registro para apagar.',
            'tables.min' => 'Selecione pelo menos um registro para apagar.',
        ]);

        if (trim($data['confirm']) !== self::CONFIRM_PHRASE) {
            throw ValidationException::withMessages([
                'confirm' => 'Frase de confirmação incorreta. Digite exatamente "' . self::CONFIRM_PHRASE . '".',
            ]);
        }

        $actor = Auth::user()?->email ?? 'desconhecido';
        $result = $this->reset->reset($actor, $data['tables']);

        $tableCount = count($result['tables']);
        return back()->with('success', "Limpeza concluída: {$result['total']} registros removidos em {$tableCount} tabela(s) selecionada(s). Pode recomeçar as importações.");
    }
}
