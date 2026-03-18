<?php

namespace App\Console\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Models\FlickrPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;

class FlickrPhotoHashBackfillCommand extends Command
{
    protected $signature = 'flickr-photo:hash-backfill
                            {--detect : Run duplicate detection after hashing}
                            {--dry-run : Show duplicates without changing statuses}
                            {--reject : Reject non-winners with REJECTED_BY_DUPLICATION instead of demoting to PENDING_REVIEW}
                            {--threshold= : Override hash threshold (default: from config)}';

    protected $description = 'Compute perceptual hashes for existing photos and optionally detect duplicates';

    public function handle(): void
    {
        $hasher = new ImageHash(new PerceptualHash());
        $threshold = (int) ($this->option('threshold') ?? config('services.flickr.hash_threshold', 10));

        $photos = FlickrPhoto::whereNull('perceptual_hash')
            ->whereNotNull('filename')
            ->whereIn('status', [
                FlickrPhotoStatus::PENDING_REVIEW,
                FlickrPhotoStatus::APPROVED,
            ])
            ->get();

        $this->info("Found {$photos->count()} photos without hashes.");

        $hashed = 0;
        foreach ($photos as $photo) {
            $path = $photo->getFilePath();
            if (!$path || !File::exists($path)) {
                $this->warn("{$photo->id}: file missing, skipping");
                continue;
            }

            try {
                $photo->perceptual_hash = $hasher->hash($path)->getIntegers()[0];
                $photo->save();
                $hashed++;
                $this->line("{$photo->id}: hashed ({$photo->perceptual_hash})");
            } catch (\Exception $e) {
                $this->error("{$photo->id}: hash failed - {$e->getMessage()}");
            }
        }

        $this->info("Hashed {$hashed} photos.");

        if (!$this->option('detect')) {
            $this->info('Run with --detect to find duplicates.');
            return;
        }

        $this->detectDuplicates($threshold);
    }

    private function detectDuplicates(int $threshold): void
    {
        $this->newLine();
        $this->info("Detecting duplicates (threshold: {$threshold})...");

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — no status changes will be made.');
        }

        $photos = FlickrPhoto::whereNotNull('perceptual_hash')
            ->whereIn('status', [
                FlickrPhotoStatus::PENDING_REVIEW,
                FlickrPhotoStatus::APPROVED,
            ])
            ->orderByDesc('status') // APPROVED (5) first, then PENDING_REVIEW (3)
            ->get();

        $processed = collect();
        $groupId = 0;

        foreach ($photos as $photo) {
            if ($processed->contains($photo->id)) {
                continue;
            }

            $duplicates = $photos->filter(function ($other) use ($photo, $threshold, $processed) {
                if ($other->id === $photo->id || $processed->contains($other->id)) {
                    return false;
                }
                $hamming = $this->hammingDistance($photo->perceptual_hash, $other->perceptual_hash);
                return $hamming <= $threshold;
            });

            if ($duplicates->isEmpty()) {
                continue;
            }

            $groupId++;
            $group = collect([$photo])->merge($duplicates);
            $winner = $group->sortByDesc(fn ($p) => File::exists($p->getFilePath() ?? '') ? File::size($p->getFilePath()) : 0)->first();

            $this->newLine();
            $this->info("Group {$groupId}: {$group->count()} similar photos");
            $this->table(
                ['ID', 'Status', 'File Size', 'Winner', 'Owner'],
                $group->map(fn ($p) => [
                    $p->id,
                    $p->status->name,
                    File::exists($p->getFilePath() ?? '') ? number_format(File::size($p->getFilePath())) : 'N/A',
                    $p->id === $winner->id ? '★' : '',
                    $p->owner_username ?: $p->owner,
                ])
            );

            if (!$dryRun) {
                $reject = $this->option('reject');
                foreach ($group as $p) {
                    if ($p->id === $winner->id) {
                        continue;
                    }
                    if ($reject) {
                        $p->status = FlickrPhotoStatus::REJECTED_BY_DUPLICATION;
                        $p->save();
                        $this->warn("  {$p->id}: rejected as duplicate");
                    } elseif ($p->status == FlickrPhotoStatus::APPROVED) {
                        $p->status = FlickrPhotoStatus::PENDING_REVIEW;
                        $p->save();
                        $this->warn("  {$p->id}: demoted APPROVED → PENDING_REVIEW");
                    }
                    $this->sendReply($p, $winner, false);
                }
                $this->sendReply($winner, $group->first(fn ($p) => $p->id !== $winner->id), true);
            }

            $processed = $processed->merge($group->pluck('id'));
        }

        if ($groupId === 0) {
            $this->info('No duplicates found.');
        } else {
            $this->newLine();
            $this->info("Found {$groupId} duplicate group(s).");
        }
    }

    private function sendReply(FlickrPhoto $photo, FlickrPhoto $otherPhoto, bool $isWinner): void
    {
        if (empty($photo->message_id)) {
            return;
        }

        try {
            $text = $isWinner
                ? "Kept as best quality. Duplicate of {$otherPhoto->id} demoted."
                : "Duplicate of {$otherPhoto->id} (better quality). Review needed.";

            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $photo->message_id,
                'text' => $text,
                'reply_markup' => new InlineKeyboard([
                    ['text' => '❌Delete', 'callback_data' => 'flickr_delete ' . $photo->id],
                ]),
            ]);
        } catch (\Exception $e) {
            $this->error("  {$photo->id}: Telegram reply failed - {$e->getMessage()}");
        }
    }

    private function hammingDistance(int $hash1, int $hash2): int
    {
        $xor = $hash1 ^ $hash2;
        $count = 0;
        for ($i = 0; $i < 64; $i++) {
            $count += ($xor >> $i) & 1;
        }
        return $count;
    }
}
