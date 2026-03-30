<?php

namespace App\Services;

use App\Models\MarketplaceQuestion;
use App\Models\AutoReplyRule;
use App\Services\MercadoLivreApiService;
use Illuminate\Support\Facades\Log;

class QuestionAutomationService
{
    protected $apiService;
    protected $aiService;

    public function __construct(MercadoLivreApiService $apiService, QuestionAIService $aiService)
    {
        $this->apiService = $apiService;
        $this->aiService = $aiService;
    }

    /**
     * Tenta responder automaticamente uma pergunta com base nas regras ou IA.
     */
    public function process(MarketplaceQuestion $question)
    {
        if ($question->status !== 'unanswered') return;

        // 1. Tentar Regras Manuais (Ativado pelo usuário)
        $rules = AutoReplyRule::where('company_id', $question->company_id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($question->question_text, $rule->keywords)) {
                $this->sendReply($question, $rule->reply_text);
                return true;
            }
        }

        // 2. Fallback: Inteligência Artificial (Magis5 Evolution)
        $productTitle = $question->product_title ?? 'Produto'; // Assumindo que temos o título
        $aiResponse = $this->aiService->generateSmartResponse($question->question_text, $productTitle);
        
        if ($aiResponse) {
            $this->sendReply($question, $aiResponse . " [Atendimento Automático IA]");
            return true;
        }

        return false;
    }

    protected function matches(string $text, string $keywords): bool
    {
        $text = mb_strtolower($text);
        $keywordsArray = explode(',', $keywords);

        foreach ($keywordsArray as $keyword) {
            $keyword = trim(mb_strtolower($keyword));
            if (empty($keyword)) continue;
            
            // Busca simples de substring
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function sendReply(MarketplaceQuestion $question, string $reply)
    {
        try {
            $this->apiService->forCompany($question->company_id)
                ->answerQuestion($question->external_id, $reply);

            $question->update([
                'answer_text' => $reply,
                'status' => 'answered'
            ]);

            Log::info("Auto-reply sent for question {$question->external_id}");
        } catch (\Exception $e) {
            Log::error("Failed to send auto-reply for question {$question->external_id}: " . $e->getMessage());
        }
    }
}
