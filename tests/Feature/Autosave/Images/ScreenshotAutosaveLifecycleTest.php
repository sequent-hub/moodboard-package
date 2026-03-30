<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Images;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;
use Throwable;

class ScreenshotAutosaveLifecycleTest extends AbstractAutosaveTestCase
{
    public function test_it_preserves_image_id_in_screenshot_blob_to_uploaded_png_lifecycle(): void
    {
        $boardId = 'board-screenshot-lifecycle-' . substr(md5((string) microtime(true)), 0, 8);
        $objectId = 'screenshot-obj-1';
        $dataUrl = $this->screenshotDataUrl();

        $firstSavePayload = [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Screenshot lifecycle board',
                'objects' => [
                    [
                        'id' => $objectId,
                        'type' => 'image',
                        'src' => $dataUrl,
                        'position' => ['x' => 150, 'y' => 100],
                        'width' => 1,
                        'height' => 1,
                        'properties' => ['name' => 'screenshot-buffer'],
                    ],
                ],
            ],
        ];

        $this->saveBoardStateV2($boardId, $firstSavePayload['boardData']);

        $afterFirstSave = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $firstObject = $afterFirstSave->json('data.state.objects.0');

        $this->assertSame($objectId, $firstObject['id'] ?? null);
        $this->assertArrayNotHasKey('imageId', $firstObject, 'imageId should be absent before upload.');
        $this->assertNotEmpty($firstObject['src'] ?? null, 'src should exist before image upload.');

        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => $this->metadataPngUpload('screenshot-lifecycle.png'),
            'name' => 'Screenshot lifecycle PNG',
        ])->assertOk()->assertJsonPath('success', true);
        $uploadedImageId = $this->assertUploadResponseContainsRequiredFields($uploadResponse);

        $secondSavePayload = [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Screenshot lifecycle board',
                'objects' => [
                    [
                        'id' => $objectId,
                        'type' => 'image',
                        'imageId' => $uploadedImageId,
                        'position' => ['x' => 150, 'y' => 100],
                        'width' => 1,
                        'height' => 1,
                        'properties' => ['name' => 'screenshot-file'],
                    ],
                ],
            ],
        ];

        $this->saveBoardStateV2($boardId, $secondSavePayload['boardData']);

        $afterSecondSave = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $secondObject = $afterSecondSave->json('data.state.objects.0');

        $this->assertSame($objectId, $secondObject['id'] ?? null);
        $this->assertSame($uploadedImageId, $secondObject['imageId'] ?? null);

        $this->saveBoardStateV2($boardId, [
            'name' => 'Screenshot lifecycle board',
            'objects' => [$secondObject],
        ]);

        $afterThirdSave = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $thirdObject = $afterThirdSave->json('data.state.objects.0');
        $this->assertSame($uploadedImageId, $thirdObject['imageId'] ?? null);
    }

    public function test_it_collects_diagnostics_for_repeated_screenshot_blob_lifecycle_cycles(): void
    {
        $cycles = (int) (getenv('MB_SCREENSHOT_LIFECYCLE_CYCLES') ?: 80);
        $cycles = max(1, $cycles);

        $summary = [
            'runId' => $this->diagnosticsRunId,
            'cycles' => $cycles,
            'test' => 'screenshot_blob_lifecycle',
            'startedAt' => date(DATE_ATOM),
            'completedCycles' => 0,
            'failedCycle' => null,
        ];

        try {
            for ($cycle = 1; $cycle <= $cycles; $cycle++) {
                $boardId = "board-screenshot-cycle-{$cycle}-" . substr(md5((string) microtime(true)), 0, 8);
                $objectId = "screenshot-obj-{$cycle}";
                $dataUrl = $this->screenshotDataUrl();

                $beforeUploadSavePayload = [
                    'boardId' => $boardId,
                    'boardData' => [
                        'name' => "Screenshot cycle board {$cycle}",
                        'objects' => [
                            [
                                'id' => $objectId,
                                'type' => 'image',
                                'src' => $dataUrl,
                                'position' => ['x' => 20 * $cycle, 'y' => 15 * $cycle],
                                'width' => 1,
                                'height' => 1,
                                'properties' => ['name' => "blob-image-{$cycle}"],
                            ],
                        ],
                    ],
                ];

                $this->saveBoardStateV2($boardId, $beforeUploadSavePayload['boardData']);

                $loadBeforeUpload = $this->getJson("/api/v2/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectBeforeUpload = $loadBeforeUpload->json('data.state.objects.0');
                $this->assertArrayNotHasKey('imageId', $objectBeforeUpload, "Unexpected imageId before upload, cycle {$cycle}.");
                $this->assertNotEmpty($objectBeforeUpload['src'] ?? null, "Missing src before upload, cycle {$cycle}.");

                $uploadResponse = $this->post('/api/v2/images/upload', [
                    'image' => $this->metadataPngUpload("screenshot-cycle-{$cycle}.png"),
                    'name' => "Screenshot cycle {$cycle}",
                ])->assertOk()->assertJsonPath('success', true);
                $uploadedImageId = $this->assertUploadResponseContainsRequiredFields($uploadResponse);

                $afterUploadSavePayload = [
                    'boardId' => $boardId,
                    'boardData' => [
                        'name' => "Screenshot cycle board {$cycle}",
                        'objects' => [
                            [
                                'id' => $objectId,
                                'type' => 'image',
                                'imageId' => $uploadedImageId,
                                'position' => ['x' => 20 * $cycle, 'y' => 15 * $cycle],
                                'width' => 1,
                                'height' => 1,
                                'properties' => ['name' => "file-image-{$cycle}"],
                            ],
                        ],
                    ],
                ];

                $this->saveBoardStateV2($boardId, $afterUploadSavePayload['boardData']);

                $loadAfterUpload = $this->getJson("/api/v2/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectAfterUpload = $loadAfterUpload->json('data.state.objects.0');
                $this->assertSame($uploadedImageId, $objectAfterUpload['imageId'] ?? null, "imageId mismatch in cycle {$cycle}.");

                $this->saveBoardStateV2($boardId, [
                    'name' => "Screenshot cycle board {$cycle}",
                    'objects' => [$objectAfterUpload],
                ]);

                $loadAfterResave = $this->getJson("/api/v2/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectAfterResave = $loadAfterResave->json('data.state.objects.0');
                $this->assertSame(
                    $uploadedImageId,
                    $objectAfterResave['imageId'] ?? null,
                    "imageId lost after re-save in cycle {$cycle}."
                );

                $this->writeDiagnosticsJson("screenshot-cycle-{$cycle}.json", [
                    'cycle' => $cycle,
                    'boardId' => $boardId,
                    'objectId' => $objectId,
                    'imageId' => $uploadedImageId,
                    'beforeUpload' => $objectBeforeUpload,
                    'afterUpload' => $objectAfterUpload,
                    'afterResave' => $objectAfterResave,
                    'loadBeforeUploadResponse' => $loadBeforeUpload->json(),
                    'loadAfterUploadResponse' => $loadAfterUpload->json(),
                    'loadAfterResaveResponse' => $loadAfterResave->json(),
                    'timestamp' => date(DATE_ATOM),
                ]);

                $summary['completedCycles'] = $cycle;
            }
        } catch (Throwable $e) {
            $summary['failedCycle'] = $summary['completedCycles'] + 1;
            $summary['error'] = [
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ];
            $this->writeDiagnosticsJson('screenshot-failure.json', [
                'summary' => $summary,
                'timestamp' => date(DATE_ATOM),
            ]);
            $this->writeDiagnosticsJson('screenshot-summary.json', $summary);
            throw $e;
        }

        $summary['finishedAt'] = date(DATE_ATOM);
        $this->writeDiagnosticsJson('screenshot-summary.json', $summary);
    }

    private function saveBoardStateV2(string $boardId, array $boardData): void
    {
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => (string) ($boardData['name'] ?? 'Untitled board'),
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => $boardData['objects'] ?? [],
            ],
        ])->assertOk()->assertJsonPath('success', true);
    }
}
