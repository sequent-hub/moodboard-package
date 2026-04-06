<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Stress;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;
use Illuminate\Http\UploadedFile;
use Throwable;

class ImagePersistenceStressTest extends AbstractAutosaveTestCase
{
    public function test_it_handles_ten_sequential_uploads_of_same_metadata_png_successfully(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->post('/api/v2/images/upload', [
                'image' => $this->metadataPngUpload("metadata-sequential-{$attempt}.png"),
                'name' => "Metadata sequential {$attempt}",
            ])->assertOk()->assertJsonPath('success', true);

            $this->assertUploadResponseContainsRequiredFields($response);
        }
    }

    public function test_it_keeps_src_during_ten_upload_save_load_cycles(): void
    {
        for ($cycle = 1; $cycle <= 10; $cycle++) {
            $uploadResponse = $this->post('/api/v2/images/upload', [
                'image' => $this->metadataPngUpload("metadata-cycle-{$cycle}.png"),
                'name' => "Cycle metadata {$cycle}",
            ])->assertOk()->assertJsonPath('success', true);

            $src = (string) $uploadResponse->json('data.url');
            $this->assertNotEmpty($src);
            $boardId = "board-image-cycle-{$cycle}";

            $this->saveBoardStateV2($boardId, [
                'name' => "Cycle Board {$cycle}",
                'objects' => [
                    [
                        'id' => "image-cycle-obj-{$cycle}",
                        'type' => 'image',
                        'src' => $src,
                        'position' => ['x' => 10 * $cycle, 'y' => 20 * $cycle],
                        'width' => 1,
                        'height' => 1,
                        'properties' => ['name' => "Cycle {$cycle} image"],
                    ],
                ],
            ]);

            $loadResponse = $this->getJson("/api/v2/moodboard/{$boardId}")
                ->assertOk()
                ->assertJsonPath('success', true);

            $savedSrc = $loadResponse->json('data.state.objects.0.src');
            $this->assertSame($src, $savedSrc, "src changed in cycle {$cycle}.");
        }
    }

    public function test_it_collects_diagnostics_over_extended_upload_save_load_cycles(): void
    {
        $cycles = (int) (getenv('MB_IMAGE_PERSISTENCE_CYCLES') ?: 120);
        $cycles = max(1, $cycles);

        $summary = [
            'runId' => $this->diagnosticsRunId,
            'cycles' => $cycles,
            'realDiskMode' => getenv('MB_PERSISTENCE_REAL_DISK') === '1',
            'startedAt' => date(DATE_ATOM),
            'completedCycles' => 0,
            'failedCycle' => null,
        ];

        try {
            for ($cycle = 1; $cycle <= $cycles; $cycle++) {
                $boardId = "board-image-diagnostics-{$cycle}-" . substr(md5((string) microtime(true)), 0, 8);

                $regularUploadResponse = $this->post('/api/v2/images/upload', [
                    'image' => UploadedFile::fake()->image("diag-regular-{$cycle}.png", 64, 64),
                    'name' => "Diagnostics regular {$cycle}",
                ])->assertOk()->assertJsonPath('success', true);

                $metadataUploadResponse = $this->post('/api/v2/images/upload', [
                    'image' => $this->metadataPngUpload("diag-metadata-{$cycle}.png"),
                    'name' => "Diagnostics metadata {$cycle}",
                ])->assertOk()->assertJsonPath('success', true);

                $regularSrc = $this->assertUploadResponseContainsRequiredFields($regularUploadResponse);
                $metadataSrc = $this->assertUploadResponseContainsRequiredFields($metadataUploadResponse);

                $boardData = [
                    'name' => "Diagnostics board {$cycle}",
                    'objects' => [
                        [
                            'id' => "diag-obj-regular-{$cycle}",
                            'type' => 'image',
                            'src' => $regularSrc,
                            'position' => ['x' => 100, 'y' => 100],
                            'width' => 64,
                            'height' => 64,
                            'properties' => ['name' => 'regular'],
                        ],
                        [
                            'id' => "diag-obj-meta-{$cycle}",
                            'type' => 'image',
                            'src' => $metadataSrc,
                            'position' => ['x' => 220, 'y' => 100],
                            'width' => 1,
                            'height' => 1,
                            'properties' => ['name' => 'metadata'],
                        ],
                    ],
                ];

                $saveResponses = $this->saveBoardStateV2($boardId, $boardData);

                $loadResponse = $this->getJson("/api/v2/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objects = $loadResponse->json('data.state.objects') ?? [];
                $this->assertCount(2, $objects, "Unexpected object count in cycle {$cycle}.");

                $loadedSrcs = array_values(array_filter(array_column($objects, 'src')));
                $this->assertCount(2, $loadedSrcs, "Loaded objects missing src in cycle {$cycle}.");
                $this->assertContains($regularSrc, $loadedSrcs, "Regular src missing in cycle {$cycle}.");
                $this->assertContains($metadataSrc, $loadedSrcs, "Metadata src missing in cycle {$cycle}.");

                $diagnosticRecord = [
                    'cycle' => $cycle,
                    'boardId' => $boardId,
                    'uploaded' => [
                        'regularSrc' => $regularSrc,
                        'metadataSrc' => $metadataSrc,
                    ],
                    'savedPayloadSrcs' => [
                        $boardData['objects'][0]['src'],
                        $boardData['objects'][1]['src'],
                    ],
                    'loadedSrcs' => $loadedSrcs,
                    'saveMetadataResponse' => $saveResponses['metadata']->json(),
                    'saveHistoryResponse' => $saveResponses['history']->json(),
                    'loadResponse' => $loadResponse->json(),
                    'timestamp' => date(DATE_ATOM),
                ];

                $this->writeDiagnosticsJson("cycle-{$cycle}.json", $diagnosticRecord);
                $summary['completedCycles'] = $cycle;
            }
        } catch (Throwable $e) {
            $summary['failedCycle'] = $summary['completedCycles'] + 1;
            $summary['error'] = [
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ];
            $this->writeDiagnosticsJson('failure.json', [
                'summary' => $summary,
                'timestamp' => date(DATE_ATOM),
            ]);
            $this->writeDiagnosticsJson('summary.json', $summary);
            throw $e;
        }

        $summary['finishedAt'] = date(DATE_ATOM);
        $this->writeDiagnosticsJson('summary.json', $summary);
    }

    private function saveBoardStateV2(string $boardId, array $boardData): array
    {
        $metadataResponse = $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => (string) ($boardData['name'] ?? 'Untitled board'),
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $historyResponse = $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => $boardData['objects'] ?? [],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        return [
            'metadata' => $metadataResponse,
            'history' => $historyResponse,
        ];
    }
}
