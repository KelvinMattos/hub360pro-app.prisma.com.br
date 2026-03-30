<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceQuestion;
use App\Services\MarketplaceQuestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MarketplaceQuestionController extends Controller
{
    protected MarketplaceQuestionService $service;

    public function __construct(MarketplaceQuestionService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $questions = MarketplaceQuestion::with('credential')
            ->where('company_id', Auth::user()->company_id)
            ->where('status', 'unanswered')
            ->latest()
            ->paginate(20);

        return Inertia::render('Marketplace/Questions', [
            'questions' => $questions
        ]);
    }

    public function sync()
    {
        $this->service->syncAllQuestions(Auth::user()->company_id);
        return redirect()->back()->with('success', 'Sincronização iniciada.');
    }

    public function answer(Request $request, MarketplaceQuestion $question)
    {
        $request->validate(['text' => 'required|string|max:2000']);
        
        $this->service->answerQuestion($question, $request->text);

        return redirect()->back()->with('success', 'Resposta enviada com sucesso.');
    }
}
