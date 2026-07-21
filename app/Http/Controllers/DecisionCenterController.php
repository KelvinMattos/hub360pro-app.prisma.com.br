<?php

namespace App\Http\Controllers;

use App\Services\ManagementDecisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Centro de Decisão Gerencial — a tela principal de tomada de decisão de
 * precificação, construída sobre os dados reais importados do Magazord.
 */
class DecisionCenterController extends Controller
{
    public function __construct(private ManagementDecisionService $service)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        return Inertia::render('Decision/Center', $this->service->analyze($user->company_id, $request->query('channel')));
    }
}
