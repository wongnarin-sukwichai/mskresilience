<?php

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\AiSummary;
use App\Models\User;
use Throwable;

/**
 * The AI mode of the executive summary (TOR 4.1.1(3)-4, owner 2026-11-23). Claude rewords the same facts the
 * rule-based summary uses; it must not add numbers. Safeguards:
 * - Only aggregated, community-level facts are sent (ExecutiveSummary::facts()), never personal data.
 * - Every number in the reply must appear in the facts, or the reply is stored as `rejected_numbers` and the page
 *   keeps the rule-based text.
 * - It may say which communities to watch and why; it must not recommend measures (owner).
 * - Each call is logged with its tokens and cost; a text is reused while the facts are unchanged, so the page never
 *   calls the API on its own.
 */
class SummaryWriter
{
    private const SYSTEM = <<<'TXT'
You write a short executive summary in Thai for the mayor of Maha Sarakham Municipality, Thailand, about community disaster risk (HEVC scores, 0–100).
You receive a JSON object of facts. Write 3 to 5 sentences of plain, formal Thai prose, as one paragraph.
Rules:
- Use only numbers that appear in the facts, written exactly as they appear. Do not round, convert, add, subtract or compute any new number. Fiscal years are Thai Buddhist-era years; keep them as given.
- You may say which communities need attention and why, based on the facts. Do not recommend specific measures, projects or budgets, and do not guess causes that the facts don't state.
- Skip any fact that is null. Do not mention the JSON, the rules, or yourself.
- No markdown, no bullet points, no headings.
TXT;

    public static function configured(): bool
    {
        return (string) config('ai.api_key') !== '';
    }

    public static function hash(array $facts): string
    {
        return hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Call the API once and log the result (ok, rejected_numbers or error).
     *
     * @param  array<string, mixed>  $facts
     */
    public static function generate(array $facts, ?User $user): AiSummary
    {
        $row = ['facts_hash' => self::hash($facts), 'facts' => $facts, 'model' => config('ai.model'), 'user_id' => $user?->id];

        try {
            $client = new Client(apiKey: config('ai.api_key'));
            $message = $client->messages->create(
                model: config('ai.model'),
                maxTokens: 4000,
                system: self::SYSTEM,
                outputConfig: ['effort' => 'low'],
                messages: [['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            );
        } catch (AuthenticationException) {
            return AiSummary::create($row + ['status' => 'error', 'error' => 'API key ไม่ถูกต้อง']);
        } catch (RateLimitException) {
            return AiSummary::create($row + ['status' => 'error', 'error' => 'เรียกใช้ถี่เกินกำหนด กรุณาลองใหม่ภายหลัง']);
        } catch (APIStatusException $e) {
            return AiSummary::create($row + ['status' => 'error', 'error' => 'API ตอบกลับผิดพลาด: '.mb_substr($e->getMessage(), 0, 300)]);
        } catch (APIConnectionException) {
            return AiSummary::create($row + ['status' => 'error', 'error' => 'เชื่อมต่อ API ไม่ได้ (เซิร์ฟเวอร์อาจไม่อนุญาตการเชื่อมต่อออกภายนอก)']);
        } catch (Throwable $e) {
            return AiSummary::create($row + ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        $input = $message->usage->inputTokens;
        $output = $message->usage->outputTokens;
        $row += [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => ($input * config('ai.price_input_per_mtok') + $output * config('ai.price_output_per_mtok')) / 1_000_000,
        ];
        if ($message->stopReason === 'refusal') {
            return AiSummary::create($row + ['status' => 'error', 'error' => 'AI ปฏิเสธการตอบ']);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        $unknown = self::unknownNumbers($text, $facts);

        return AiSummary::create($row + [
            'text' => $text,
            'status' => $unknown ? 'rejected_numbers' : 'ok',
            'error' => $unknown ? 'มีตัวเลขที่ไม่อยู่ในข้อมูล: '.implode(', ', $unknown) : null,
        ]);
    }

    /**
     * Numbers in $text that don't appear anywhere in the facts (community names like "ธัญญา 2" count as facts).
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public static function unknownNumbers(string $text, array $facts): array
    {
        $normalize = fn (string $n) => str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', json_encode($facts, JSON_UNESCAPED_UNICODE), $known);
        $allowed = array_flip(array_map(fn ($n) => $normalize(str_replace(',', '', $n)), $known[0]));
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $found);

        return array_values(array_unique(array_filter(
            array_map(fn ($n) => $normalize(str_replace(',', '', $n)), $found[0]),
            fn ($n) => ! isset($allowed[$n]),
        )));
    }
}
