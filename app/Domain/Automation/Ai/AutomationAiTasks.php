<?php

declare(strict_types=1);

namespace App\Domain\Automation\Ai;

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\Chatbot\OutputGuard;
use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Providers\RefusalDetector;
use App\Domain\Conversations\Notes\NotesWriter;
use App\Models\Conversation;
use App\Support\Settings\SettingsRepository;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 run-AI-task's two built sub-arms, on Doc 09's PUBLIC doors
 * and nothing else: the layered assembly (persona + guardrails + PII
 * masking), the orchestrator (quota gate, breaker, the fixtures-only egress
 * guard inside the client), the output guard and the refusal detector — and
 * Doc 04's NotesWriter::addSystemNote for the product (N-04: an internal
 * note, never a customer-visible send). Templates are Doc 09's own
 * constants. Decision rows: none are written here (the CopilotPanel refresh
 * precedent; the ai_decisions surface CHECK admits no 'automation' —
 * packet P-CLS may add one if the advisor wants these calls on the log).
 * Note bodies are i18n keys resolved at write time (NotesWriter's law).
 */
final class AutomationAiTasks
{
    private const LANGUAGE_NAMES = ['en' => 'English', 'am' => 'Amharic'];

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly ContextBuilder $contextBuilder,
        private readonly OutputGuard $outputGuard,
        private readonly RefusalDetector $refusals,
        private readonly NotesWriter $notes,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return array{note_id: string} */
    public function summarizeToNote(Conversation $conversation): array
    {
        $conversationId = $conversation->identifier();
        $language = $this->customerLanguage($conversation);
        $window = $this->window($conversationId, 40);
        if ($window === []) {
            throw new DomainException('nothing to summarize: the conversation holds no public messages');
        }
        $context = $this->contextBuilder->build(self::str($conversation->getAttribute('customer_id')), $language, $window);
        $assembled = $this->assembly->assemble('summarize_thread', ComposerActions::SUMMARIZE_TEMPLATE, $context);
        $result = $this->orchestrator->execute('summarize_thread', $assembled['messages']);
        $summary = $this->guarded($result['response'], trim((string) $result['response']->content));

        $note = $this->notes->addSystemNote($conversation, (string) __('workflow.ai_task.summary_note', ['summary' => $summary]));

        return ['note_id' => $note->identifier()];
    }

    /** @return array{note_id: string, target_language: string} */
    public function translateLastMessageToNote(Conversation $conversation): array
    {
        $conversationId = $conversation->identifier();
        /** @var object{body: ?string, language: ?string}|null $last */
        $last = DB::table('messages')->where('conversation_id', $conversationId)
            ->where('author_type', 'customer')->where('kind', '!=', 'note')->where('is_internal', false)
            ->orderByDesc('created_at')->orderByDesc('id')->first(['body', 'language']);
        if ($last === null || ! is_string($last->body) || trim($last->body) === '') {
            throw new DomainException('nothing to translate: no customer text message');
        }
        $target = $this->settings->get('tenant.default_language');
        $target = is_string($target) && isset(self::LANGUAGE_NAMES[$target]) ? $target : 'en';
        if ($last->language === $target) {
            throw new DomainException('same_language: the last customer message is already in '.$target);
        }
        $template = sprintf(ComposerActions::TRANSLATE_TEMPLATE, self::LANGUAGE_NAMES[$target], $target);
        $context = $this->contextBuilder->build(self::str($conversation->getAttribute('customer_id')), $target, [['role' => 'user', 'content' => $last->body]]);
        $assembled = $this->assembly->assemble('translate', $template, $context, [], keepReferences: true);
        $result = $this->orchestrator->execute('translate', $assembled['messages']);
        $text = $this->guarded($result['response'], trim((string) $result['response']->content));

        $note = $this->notes->addSystemNote($conversation, (string) __('workflow.ai_task.translation_note', ['language' => self::LANGUAGE_NAMES[$target], 'text' => $text]));

        return ['note_id' => $note->identifier(), 'target_language' => $target];
    }

    private function guarded(\App\Domain\Ai\Providers\ProviderResponse $response, string $text): string
    {
        if ($text === '' || $this->refusals->isPolicyRefusal($response) || $this->outputGuard->leaksSystemText($text)) {
            throw new DomainException('output_refused: the model answered nothing usable (Doc 09 guards)');
        }

        return $text;
    }

    /** @return list<array{role: string, content: string}> */
    private function window(string $conversationId, int $limit): array
    {
        /** @var list<object{author_type: string, body: ?string}> $rows */
        $rows = DB::table('messages')->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'agent', 'ai'])->where('kind', '!=', 'note')->where('is_internal', false)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get(['author_type', 'body'])->reverse()->values()->all();
        $window = [];
        foreach ($rows as $row) {
            if (is_string($row->body) && $row->body !== '') {
                $window[] = ['role' => $row->author_type === 'customer' ? 'user' : 'assistant', 'content' => $row->body];
            }
        }

        return $window;
    }

    private function customerLanguage(Conversation $conversation): string
    {
        $lang = DB::table('customers')->where('id', self::str($conversation->getAttribute('customer_id')))->value('language');

        return is_string($lang) && isset(self::LANGUAGE_NAMES[$lang]) ? $lang : 'en';
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
