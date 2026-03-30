<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class MarketplaceAccountController extends Controller
{
    public function index()
    {
        $company = Auth::user()->company;
        $credentials = Integration::where('company_id', $company->id)
            ->whereNotNull('access_token') // Only show fully connected accounts in the grid
            ->get();

        // Get "base" integrations (pending or active) to see which platforms have keys configured
        // Robust check: any record that has app_id and client_secret means the platform is "configurable"
        $configs = Integration::where('company_id', $company->id)
            ->whereNotNull('app_id')
            ->whereNotNull('client_secret')
            ->get()
            ->groupBy('platform')
            ->map(function ($items) {
                return $items->first();
            });

        return Inertia::render('Settings/MarketplaceConnections', [
            'credentials' => $credentials,
            'configs' => $configs,
            'company' => $company
        ]);
    }

    public function toggle(Integration $credential)
    {
        // $this->authorize('update', $credential); // Temporariamente desativado se não houver policy para Integration
        $credential->update(['is_active' => !$credential->is_active]);
        return redirect()->back();
    }

    public function destroy(Integration $credential)
    {
        // $this->authorize('delete', $credential);
        $credential->delete();
        return redirect()->back();
    }
}
