<?php

namespace Futurello\MoodBoard\Console\Commands;

use Futurello\MoodBoard\Models\MoodBoard;
use Futurello\MoodBoard\Models\MoodboardHistory;
use Futurello\MoodBoard\Services\LegacyAssetMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateMoodboardsToHistory extends Command
{
    protected $signature = 'moodboard:migrate-to-history
        {--execute : Run the migration (without this flag, dry-run mode is used)}
        {--board-id= : Process only a specific board_id}';

    protected $description = 'One-time migration: transfer moodboards.data into moodboard_history with asset re-upload to S3';

    private LegacyAssetMigrator $assetMigrator;

    public function handle(): int
    {
        $dryRun = !$this->option('execute');
        $singleBoardId = $this->option('board-id');

        $localStoragePath = $this->detectStoragePath();
        $this->assetMigrator = new LegacyAssetMigrator($localStoragePath);

        $this->printHeader($dryRun, $singleBoardId);

        $query = MoodBoard::query()->orderBy('id');
        if ($singleBoardId !== null) {
            $query->where('board_id', $singleBoardId);
        }

        $boards = $query->get();

        if ($boards->isEmpty()) {
            $this->warn('No moodboards found.');
            return self::SUCCESS;
        }

        $this->info("Found {$boards->count()} moodboard(s) to process.");
        $this->newLine();

        $totalStats = [
            'processed' => 0,
            'skipped_existing' => 0,
            'skipped_empty' => 0,
            'failed' => 0,
            'uploaded_to_s3' => 0,
            'marked_as_placeholder' => 0,
            'normalized_to_cdn' => 0,
        ];

        $bar = $this->output->createProgressBar($boards->count());
        $bar->start();

        foreach ($boards as $board) {
            $result = $this->processBoard($board, $dryRun);

            $totalStats[$result['status']]++;
            if ($result['status'] === 'processed') {
                $assetStats = $result['asset_stats'];
                $totalStats['uploaded_to_s3'] += $assetStats['uploaded_to_s3'];
                $totalStats['marked_as_placeholder'] += $assetStats['marked_as_placeholder'];
                $totalStats['normalized_to_cdn'] += $assetStats['normalized_to_cdn'];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary($totalStats, $dryRun);

        return self::SUCCESS;
    }

    private function processBoard(MoodBoard $board, bool $dryRun): array
    {
        $boardId = $board->board_id;

        $existing = MoodboardHistory::query()
            ->where('moodboard_id', $boardId)
            ->exists();

        if ($existing) {
            $this->logBoard($boardId, 'SKIP', 'Already has history records');
            return ['status' => 'skipped_existing'];
        }

        $data = $board->data;

        if (!is_array($data) || empty($data)) {
            $this->logBoard($boardId, 'SKIP', 'Empty data');
            return ['status' => 'skipped_empty'];
        }

        $this->assetMigrator->resetCounters();

        try {
            $processedState = $this->assetMigrator->processState($data, $dryRun);
            $assetStats = $this->assetMigrator->getStats();

            if (!$dryRun) {
                $this->insertHistoryRecord($boardId, $processedState, $board->created_at);
            }

            $this->logBoard($boardId, $dryRun ? 'DRY-RUN OK' : 'MIGRATED', sprintf(
                'S3: %d, placeholder: %d, CDN-norm: %d, errors: %d',
                $assetStats['uploaded_to_s3'],
                $assetStats['marked_as_placeholder'],
                $assetStats['normalized_to_cdn'],
                count($assetStats['errors'])
            ));

            foreach ($assetStats['errors'] as $error) {
                $this->logBoard($boardId, 'WARN', $error);
            }

            return ['status' => 'processed', 'asset_stats' => $assetStats];
        } catch (\Throwable $e) {
            $this->logBoard($boardId, 'ERROR', $e->getMessage());
            Log::error('MigrateMoodboardsToHistory: board failed', [
                'board_id' => $boardId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['status' => 'failed'];
        }
    }

    private function insertHistoryRecord(string $boardId, array $state, $createdAt): void
    {
        $stateHash = $this->buildStateHash($state);

        DB::transaction(function () use ($boardId, $state, $stateHash, $createdAt): void {
            $alreadyExists = MoodboardHistory::query()
                ->where('moodboard_id', $boardId)
                ->lockForUpdate()
                ->exists();

            if ($alreadyExists) {
                return;
            }

            MoodboardHistory::query()->create([
                'moodboard_id' => $boardId,
                'version' => 1,
                'state_json' => $state,
                'state_hash' => $stateHash,
                'action_type' => 'legacy_migration',
                'created_at' => $createdAt ?? now(),
                'created_by' => 'system:legacy_migration',
            ]);
        });
    }

    private function buildStateHash(array $state): string
    {
        $encoded = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        return hash('sha256', $encoded ?: '{}');
    }

    private function detectStoragePath(): string
    {
        return storage_path('app');
    }

    private function logBoard(string $boardId, string $level, string $message): void
    {
        $line = "[{$level}] board_id={$boardId}: {$message}";

        if ($level === 'ERROR') {
            $this->error($line);
        } elseif ($level === 'WARN') {
            $this->warn("  {$line}");
        } else {
            $this->line("  {$line}");
        }

        Log::info("MigrateMoodboardsToHistory: {$line}");
    }

    private function printHeader(bool $dryRun, ?string $singleBoardId): void
    {
        $this->newLine();
        $this->info('=== Moodboard Legacy Migration ===');
        $this->info('Mode: ' . ($dryRun ? 'DRY-RUN (no changes)' : 'EXECUTE (writing to DB & S3)'));

        if ($singleBoardId !== null) {
            $this->info("Board filter: {$singleBoardId}");
        }

        $this->info('CDN Base URL: ' . (env('MOODBOARD_IMAGE_CDN_BASE_URL') ?: '(not set)'));
        $this->info('Storage path: ' . $this->detectStoragePath());
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm('This will write data to moodboard_history and upload files to S3. Continue?')) {
                $this->info('Aborted.');
                exit(0);
            }
        }
    }

    private function printSummary(array $stats, bool $dryRun): void
    {
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Skipped (existing history)', $stats['skipped_existing']],
                ['Skipped (empty data)', $stats['skipped_empty']],
                ['Failed', $stats['failed']],
                ['Assets uploaded to S3', $stats['uploaded_to_s3']],
                ['Assets marked as placeholder', $stats['marked_as_placeholder']],
                ['Assets normalized to CDN', $stats['normalized_to_cdn']],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a DRY-RUN. No data was written. Use --execute to apply changes.');
        }
    }
}
