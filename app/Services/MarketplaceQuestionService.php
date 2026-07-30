<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\MarketplaceQuestion;
use Illuminate\Support\Facades\Log;

class MarketplaceQuestionService
{
    protected MarketplaceManager $manager;
    protected QuestionAutomationService $automationService;

    public function __construct(MarketplaceManager $manager, QuestionAutomationService $automationService)
    {
        $this->manager = $manager;
        $this->automationService = $automationService;
    }

    /**
     * @return array{ok: bool, synced: int, errors: array<string>, message: string}
     */
    public function syncAllQuestions(int $companyId): array
    {
        $credentials = Integration::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('platform', '!=', 'bling')
            ->get();

        if ($credentials->isEmpty()) {
            return ['ok' => false, 'synced' => 0, 'errors' => [], 'message' => 'Nenhuma integração ativa encontrada.'];
        }

        $synced = 0;
        $errors = [];

        foreach ($credentials as $credential) {
            $result = $this->syncQuestions($credential);
            if ($result['ok']) {
                $synced++;
            } else {
                $errors[] = "{$credential->account_nickname} ({$credential->platform}): {$result['error']}";
            }
        }

        $ok = $synced > 0;
        $message = $ok
            ? ($errors ? "Sincronizado com falha parcial em: " . implode('; ', $errors) : 'Sincronização concluída.')
            : ('Falha ao sincronizar todas as integrações: ' . implode('; ', $errors));

        return ['ok' => $ok, 'synced' => $synced, 'errors' => $errors, 'message' => $message];
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function syncQuestions(Integration $credential): array
    {
        try {
            $adapter = $this->manager->adapter($credential);
            $externalQuestions = $adapter->fetchQuestions($credential);

            foreach ($externalQuestions as $q) {
                $question = MarketplaceQuestion::updateOrCreate(
                    ['external_id' => $q['id'], 'company_id' => $credential->company_id],
                    [
                        'integration_id' => $credential->id,
                        'product_external_id' => $q['item_id'],
                        'question_text' => $q['text'],
                        'status' => $q['status'],
                        'received_at' => $q['date_created'],
                        'buyer_username' => $q['from']['name'] ?? 'Comprador',
                    ]
                );

                // Automação de Resposta (AI Marketplace Turbo)
                if ($question->wasRecentlyCreated || $question->status === 'unanswered') {
                    $this->automationService->process($question);
                }
            }

            return ['ok' => true, 'error' => null];
        } catch (\Exception $e) {
            Log::error("Error syncing questions for integration {$credential->id}: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function answerQuestion(MarketplaceQuestion $question, string $text)
    {
        $adapter = $this->manager->adapter($question->credential);
        $adapter->answerQuestion($question->credential, $question->external_id, $text);

        $question->update([
            'answer_text' => $text,
            'status' => 'answered'
        ]);
    }
}
