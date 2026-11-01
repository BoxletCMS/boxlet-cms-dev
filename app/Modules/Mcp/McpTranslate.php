<?php

namespace App\Modules\Mcp;

use App\Core\Container;
use App\Core\Db;
use App\Modules\Ai\AiController;
use App\Modules\Ai\AiQueue;
use App\Modules\Ai\AiSettings;
use App\Modules\Pages\TranslationController;
use App\Support\Url;
use RuntimeException;

/**
 * TRANSLATION OVER MCP, THROUGH THE SITE'S OWN (PLAN.md D-299; the owner, 2026-10-23: "when the
 * Croatian pages are done, translate them into English" should go through Boxlet's translation,
 * not a model writing pages of its own). translate_pages is Copy into language with "and
 * translate with AI": each page copied as its linked translation (its forms and its place in the
 * menus with it) and queued (AiQueue); translation_progress works the queue as the admin's
 * translation screen does, part by part (AiController::translateNext), for as long as a call
 * may take, and says how far it is. The owner's own API key, drafts, the activity log: all as
 * when the owner does it.
 */
final class McpTranslate
{
    /**
     * How long one translation_progress call keeps taking parts: an MCP client waits for the
     * answer (Claude Code gives up after a minute), and one part can take twenty seconds.
     */
    private const SECONDS = 20.0;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<mixed> $arguments
     * @return array<string, mixed>
     */
    public function pages(array $arguments): array
    {
        $db = $this->db();
        $to = is_string($arguments['locale'] ?? null) ? trim($arguments['locale']) : '';
        $language = $db->one('SELECT label FROM locales WHERE code = ? AND enabled = 1', [$to]);
        if ($language === null) {
            throw new RuntimeException("The site has no language {$to}; get_site lists them.");
        }
        $ids = [];
        foreach (is_array($arguments['pages'] ?? null) ? $arguments['pages'] : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if ($ids === []) {
            throw new RuntimeException('pages must list the ids of the pages to translate (list_pages gives them).');
        }
        // Before anything is copied: a copy without the translation is a page of the original's
        // words under another language, which is what this tool is here to prevent.
        if (!AiSettings::ready($db, $this->appKey())) {
            throw new RuntimeException('AI translation is not set up on this site: the owner adds an API key in Settings → AI → Translation. Nothing was copied.');
        }

        $copied = TranslationController::copyAll($db, $this->container->get('blocks'), array_values($ids), $to);
        $parts = 0;
        foreach (array_merge($copied['made'], $copied['again']) as $id) {
            $parts += AiQueue::page($db, $this->container->get('blocks'), $id);
        }

        return [
            'language' => ['code' => $to, 'label' => (string) $language['label']],
            'pages' => array_map(static fn (array $pair): array => [
                'original' => $pair['from'],
                'translation' => $pair['to'],
                'new' => $pair['new'],
                'review' => Url::withOrigin(Url::admin('pages', $pair['to'])),
            ], $copied['pairs']),
            'passed' => $copied['passed'] - count($copied['again']),
            'queued_parts' => $parts,
            'progress' => $this->summary(),
            'next' => $parts > 0 ? 'Call translation_progress until pending is 0.' : 'Nothing to translate.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        $started = microtime(true);
        $stopped = '';
        while (AiQueue::next($this->db()) !== null && microtime(true) - $started < self::SECONDS) {
            $stopped = AiController::translateNext($this->container);
            if ($stopped !== '') {
                break;
            }
        }
        $summary = $this->summary();

        return $summary + [
            'stopped' => $stopped,
            'next' => match (true) {
                $stopped !== '' => 'Stopped: tell the owner what it says; the rest waits until they fix it.',
                $summary['pending'] > 0 => 'Call translation_progress again.',
                $summary['failed'] > 0 => 'Done, with failed parts: the owner can try them again on the translation screen.',
                default => 'Done. The translations are drafts for the owner to review and publish.',
            },
            'screen' => Url::withOrigin(Url::admin('translate')),
        ];
    }

    /**
     * How far the queue is, its pages with their review addresses.
     *
     * @return array{done: int, failed: int, pending: int, total: int, pages: list<array<string, mixed>>, messages: list<string>}
     */
    private function summary(): array
    {
        $progress = AiQueue::progress($this->db());
        $progress['pages'] = array_map(static fn (array $page): array => $page + ['review' => Url::withOrigin(Url::admin('pages', $page['id']))], $progress['pages']);

        return $progress;
    }

    private function appKey(): string
    {
        return (string) $this->container->get('config')->get('app.key');
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
