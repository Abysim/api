<?php

namespace App\Http\Controllers;

use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Read/write endpoints for the translation-QA workflow.
 *
 * These let the workflow's haiku agents fetch an article and post the corrected
 * translation over plain HTTPS instead of the orchestrator shelling into bigcats.
 * ALL verification (auth, optimistic lock, byte-cap, payload shape) lives here in
 * PHP so the AI never spends tokens validating — it only relays the verdict.
 */
class NewsTranslationController extends Controller
{
    /** MySQL TEXT column cap for publish_content, in BYTES (not characters). */
    private const PUBLISH_CONTENT_MAX_BYTES = 65535;

    /**
     * Read an article's translatable fields for the workflow.
     *
     * Returns everything the workflow needs to decide whether to translate
     * (language/is_translated), pick the science vs publicistic variant
     * (platform), adapt dates (date/dateFormatted), plus `lock` — an md5 of
     * publish_content used as an optimistic-concurrency token by update().
     */
    public function show(int $id): JsonResponse
    {
        $news = News::find($id);

        if ($news === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json([
            'id' => $news->id,
            'publish_title' => $news->publish_title,
            'publish_content' => $news->publish_content,
            'platform' => $news->platform,
            'language' => $news->language,
            'is_translated' => (bool) $news->is_translated,
            'date' => optional($news->date)->format('Y-m-d'),
            'dateFormatted' => optional($news->date)->translatedFormat('j F Y'),
            'lock' => md5((string) $news->publish_content),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Write the QA-corrected translation back, fully verified in PHP.
     *
     * Verifies: payload shape; the article still exists; publish_content is
     * unchanged since the read (optimistic lock → 409); the new content fits the
     * TEXT byte cap (→ 422, never a silent truncation). Uses save() — not a
     * query-builder update() — so the News observer fires and the Telegram
     * caption refreshes. Persists the analyze↔apply cycle count to analysis_count
     * when the workflow reports it.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'   => 'required|string|max:1000',
            'content' => 'required|string',
            'lock'    => 'required|string|size:32',
            'cycles'  => 'nullable|integer|min:0|max:64',
            'outcome' => 'nullable|string|max:64',
        ]);

        // Reject malformed UTF-8 before it can reach the Telegram caption or the
        // public site, then strip C0 control bytes (keeping \t \n \r) that have no
        // place in article text and would corrupt JSON/Telegram payloads.
        if (!mb_check_encoding($data['title'], 'UTF-8') || !mb_check_encoding($data['content'], 'UTF-8')) {
            return response()->json(['error' => 'title/content must be valid UTF-8'], 422);
        }
        $title   = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $data['title']);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $data['content']);

        // Byte-cap guard: validate BYTES, not characters — Ukrainian is ~2 bytes/char
        // in UTF-8, so a character-count check would let ~131 KB into a 64 KB column.
        $bytes = strlen($content);
        if ($bytes > self::PUBLISH_CONTENT_MAX_BYTES) {
            return response()->json([
                'error' => 'content exceeds TEXT column byte cap',
                'bytes' => $bytes,
                'max'   => self::PUBLISH_CONTENT_MAX_BYTES,
            ], 422);
        }

        $news = News::find($id);

        if ($news === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        // Optimistic lock: reject if publish_content changed since the read.
        // md5 is computed in PHP on both ends so charset/collation can't diverge.
        if (md5((string) $news->publish_content) !== $data['lock']) {
            return response()->json([
                'error' => 'optimistic lock failed — publish_content changed since read',
            ], 409);
        }

        $news->publish_title   = $title;
        $news->publish_content = $content;
        $news->is_translated   = true;
        $news->is_deep         = true;
        $news->is_deepest      = true;
        $news->is_auto         = false;
        if (array_key_exists('cycles', $data) && $data['cycles'] !== null) {
            $news->analysis_count = $data['cycles'];
        }
        $news->save();

        Log::info('translation-qa writeback: ' . json_encode([
            'id'      => $news->id,
            'cycles'  => $data['cycles'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'bytes'   => $bytes,
            'ip'      => $request->ip(),
            'ua'      => $request->userAgent(),
        ], JSON_UNESCAPED_UNICODE));

        return response()->json([
            'ok'             => true,
            'id'             => $news->id,
            'content_md5'    => md5($content),
            'bytes'          => $bytes,
            'analysis_count' => $news->analysis_count,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
