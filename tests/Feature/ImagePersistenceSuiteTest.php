<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImagePersistenceSuiteTest extends TestCase
{
    private string $diagnosticsRunId;
    private string $diagnosticsDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MB_PERSISTENCE_REAL_DISK') !== '1') {
            Storage::fake('local');
        }

        $this->diagnosticsRunId = 'run_' . date('Ymd_His') . '_' . substr(md5((string) microtime(true)), 0, 8);
        $this->diagnosticsDir = __DIR__ . '/../_artifacts/image-persistence/' . $this->diagnosticsRunId;
        $this->ensureDiagnosticsDirectoryExists($this->diagnosticsDir);
    }

    public function test_it_uploads_regular_and_metadata_png_and_files_are_accessible(): void
    {
        $regularUpload = $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->image('regular.png', 120, 80),
            'name' => 'Regular PNG',
        ])->assertOk()->assertJsonPath('success', true);

        $regularImageId = $this->assertUploadResponseContainsRequiredFields($regularUpload);

        $metadataUpload = $this->post('/api/images/upload', [
            'image' => $this->metadataPngUpload('metadata.png'),
            'name' => 'Metadata PNG',
        ])->assertOk()->assertJsonPath('success', true);

        $metadataImageId = $this->assertUploadResponseContainsRequiredFields($metadataUpload);

        $regularFileResponse = $this->get("/api/images/{$regularImageId}/file")->assertOk();
        $this->assertFileResponseHasNonEmptyBody($regularFileResponse, 'Regular PNG file body is empty.');

        $metadataFileResponse = $this->get("/api/images/{$metadataImageId}/file")->assertOk();
        $this->assertFileResponseHasNonEmptyBody($metadataFileResponse, 'Metadata PNG file body is empty.');
    }

    public function test_it_saves_and_loads_image_object_without_src_and_keeps_image_id(): void
    {
        $imageId = $this->post('/api/images/upload', [
            'image' => $this->metadataPngUpload('single-board-metadata.png'),
            'name' => 'Single board metadata PNG',
        ])->assertOk()->json('data.imageId');

        $this->assertNotEmpty($imageId);

        $boardId = 'board-image-single';
        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Image board',
                'objects' => [
                    [
                        'id' => 'image-obj-1',
                        'type' => 'image',
                        'imageId' => $imageId,
                        'position' => ['x' => 10, 'y' => 20],
                        'width' => 320,
                        'height' => 240,
                        'properties' => ['name' => 'single image object'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $loadResponse = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $objects = $loadResponse->json('data.objects');
        $this->assertCount(1, $objects);
        $this->assertSame('image', $objects[0]['type']);
        $this->assertSame($imageId, $objects[0]['imageId'] ?? null);
    }

    public function test_it_persists_multiple_images_in_one_board_including_metadata_png(): void
    {
        $regularImageId = $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->image('multi-regular.png', 100, 60),
            'name' => 'Multi Regular',
        ])->assertOk()->json('data.imageId');

        $metadataImageId = $this->post('/api/images/upload', [
            'image' => $this->metadataPngUpload('multi-metadata.png'),
            'name' => 'Multi Metadata',
        ])->assertOk()->json('data.imageId');

        $this->assertNotEmpty($regularImageId);
        $this->assertNotEmpty($metadataImageId);

        $boardId = 'board-image-multi';
        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Multiple images board',
                'objects' => [
                    [
                        'id' => 'img-obj-1',
                        'type' => 'image',
                        'imageId' => $regularImageId,
                        'position' => ['x' => 0, 'y' => 0],
                        'width' => 100,
                        'height' => 60,
                        'properties' => ['name' => 'regular'],
                    ],
                    [
                        'id' => 'img-obj-2',
                        'type' => 'image',
                        'imageId' => $metadataImageId,
                        'position' => ['x' => 120, 'y' => 0],
                        'width' => 1,
                        'height' => 1,
                        'properties' => ['name' => 'metadata'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $loadResponse = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $objects = $loadResponse->json('data.objects');
        $this->assertCount(2, $objects);

        $ids = array_column($objects, 'imageId');
        $this->assertContains($regularImageId, $ids);
        $this->assertContains($metadataImageId, $ids);
    }

    public function test_it_returns_validation_errors_for_missing_board_id_and_upload_issues(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardData' => ['objects' => []],
        ])->assertStatus(422);

        $this->post('/api/images/upload', [], [
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $unsupportedMime = UploadedFile::fake()->create('not-supported.svg', 10, 'image/svg+xml');

        $this->post('/api/images/upload', [
            'image' => $unsupportedMime,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }

    public function test_it_handles_ten_sequential_uploads_of_same_metadata_png_successfully(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->post('/api/images/upload', [
                'image' => $this->metadataPngUpload("metadata-sequential-{$attempt}.png"),
                'name' => "Metadata sequential {$attempt}",
            ])->assertOk()->assertJsonPath('success', true);

            $this->assertUploadResponseContainsRequiredFields($response);
        }
    }

    public function test_it_keeps_image_id_during_ten_upload_save_load_cycles(): void
    {
        for ($cycle = 1; $cycle <= 10; $cycle++) {
            $uploadResponse = $this->post('/api/images/upload', [
                'image' => $this->metadataPngUpload("metadata-cycle-{$cycle}.png"),
                'name' => "Cycle metadata {$cycle}",
            ])->assertOk()->assertJsonPath('success', true);

            $imageId = $this->assertUploadResponseContainsRequiredFields($uploadResponse);
            $boardId = "board-image-cycle-{$cycle}";

            $this->postJson('/api/moodboard/save', [
                'boardId' => $boardId,
                'boardData' => [
                    'name' => "Cycle Board {$cycle}",
                    'objects' => [
                        [
                            'id' => "image-cycle-obj-{$cycle}",
                            'type' => 'image',
                            'imageId' => $imageId,
                            'position' => ['x' => 10 * $cycle, 'y' => 20 * $cycle],
                            'width' => 1,
                            'height' => 1,
                            'properties' => ['name' => "Cycle {$cycle} image"],
                        ],
                    ],
                ],
            ])->assertOk()->assertJsonPath('success', true);

            $loadResponse = $this->getJson("/api/moodboard/{$boardId}")
                ->assertOk()
                ->assertJsonPath('success', true);

            $savedImageId = $loadResponse->json('data.objects.0.imageId');
            $this->assertSame($imageId, $savedImageId, "Image ID changed in cycle {$cycle}.");
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

                $regularUploadResponse = $this->post('/api/images/upload', [
                    'image' => UploadedFile::fake()->image("diag-regular-{$cycle}.png", 64, 64),
                    'name' => "Diagnostics regular {$cycle}",
                ])->assertOk()->assertJsonPath('success', true);

                $metadataUploadResponse = $this->post('/api/images/upload', [
                    'image' => $this->metadataPngUpload("diag-metadata-{$cycle}.png"),
                    'name' => "Diagnostics metadata {$cycle}",
                ])->assertOk()->assertJsonPath('success', true);

                $regularImageId = $this->assertUploadResponseContainsRequiredFields($regularUploadResponse);
                $metadataImageId = $this->assertUploadResponseContainsRequiredFields($metadataUploadResponse);

                $savePayload = [
                    'boardId' => $boardId,
                    'boardData' => [
                        'name' => "Diagnostics board {$cycle}",
                        'objects' => [
                            [
                                'id' => "diag-obj-regular-{$cycle}",
                                'type' => 'image',
                                'imageId' => $regularImageId,
                                'position' => ['x' => 100, 'y' => 100],
                                'width' => 64,
                                'height' => 64,
                                'properties' => ['name' => 'regular'],
                            ],
                            [
                                'id' => "diag-obj-meta-{$cycle}",
                                'type' => 'image',
                                'imageId' => $metadataImageId,
                                'position' => ['x' => 220, 'y' => 100],
                                'width' => 1,
                                'height' => 1,
                                'properties' => ['name' => 'metadata'],
                            ],
                        ],
                    ],
                ];

                $saveResponse = $this->postJson('/api/moodboard/save', $savePayload)
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $loadResponse = $this->getJson("/api/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objects = $loadResponse->json('data.objects') ?? [];
                $this->assertCount(2, $objects, "Unexpected object count in cycle {$cycle}.");

                $loadedIds = array_values(array_filter(array_column($objects, 'imageId')));
                $this->assertCount(2, $loadedIds, "Loaded objects missing imageId in cycle {$cycle}.");
                $this->assertContains($regularImageId, $loadedIds, "Regular imageId missing in cycle {$cycle}.");
                $this->assertContains($metadataImageId, $loadedIds, "Metadata imageId missing in cycle {$cycle}.");

                $regularFile = $this->get("/api/images/{$regularImageId}/file")->assertOk();
                $metadataFile = $this->get("/api/images/{$metadataImageId}/file")->assertOk();

                $regularFileStats = $this->assertFileResponseHasNonEmptyBody(
                    $regularFile,
                    "Regular file body is empty in cycle {$cycle}."
                );
                $metadataFileStats = $this->assertFileResponseHasNonEmptyBody(
                    $metadataFile,
                    "Metadata file body is empty in cycle {$cycle}."
                );

                $diagnosticRecord = [
                    'cycle' => $cycle,
                    'boardId' => $boardId,
                    'uploaded' => [
                        'regularImageId' => $regularImageId,
                        'metadataImageId' => $metadataImageId,
                    ],
                    'savedPayloadImageIds' => [
                        $savePayload['boardData']['objects'][0]['imageId'],
                        $savePayload['boardData']['objects'][1]['imageId'],
                    ],
                    'loadedImageIds' => $loadedIds,
                    'saveResponse' => $saveResponse->json(),
                    'loadResponse' => $loadResponse->json(),
                    'fileChecks' => [
                        'regularLength' => $regularFileStats['length'],
                        'metadataLength' => $metadataFileStats['length'],
                        'regularSha256' => $regularFileStats['sha256'],
                        'metadataSha256' => $metadataFileStats['sha256'],
                    ],
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

        $this->postJson('/api/moodboard/save', $firstSavePayload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $afterFirstSave = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $firstObject = $afterFirstSave->json('data.objects.0');

        $this->assertSame($objectId, $firstObject['id'] ?? null);
        $this->assertArrayNotHasKey('imageId', $firstObject, 'imageId should be absent before upload.');
        $this->assertNotEmpty($firstObject['src'] ?? null, 'src should exist before image upload.');

        $uploadResponse = $this->post('/api/images/upload', [
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

        $this->postJson('/api/moodboard/save', $secondSavePayload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $afterSecondSave = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $secondObject = $afterSecondSave->json('data.objects.0');

        $this->assertSame($objectId, $secondObject['id'] ?? null);
        $this->assertSame($uploadedImageId, $secondObject['imageId'] ?? null);
        $this->assertNotEmpty(
            $secondObject['src'] ?? null,
            'src should be restored from imageId after second save/load.'
        );

        // Additional stability step: save loaded payload once more and verify imageId remains.
        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Screenshot lifecycle board',
                'objects' => [$secondObject],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $afterThirdSave = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $thirdObject = $afterThirdSave->json('data.objects.0');
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

                $this->postJson('/api/moodboard/save', $beforeUploadSavePayload)
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $loadBeforeUpload = $this->getJson("/api/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectBeforeUpload = $loadBeforeUpload->json('data.objects.0');
                $this->assertArrayNotHasKey('imageId', $objectBeforeUpload, "Unexpected imageId before upload, cycle {$cycle}.");
                $this->assertNotEmpty($objectBeforeUpload['src'] ?? null, "Missing src before upload, cycle {$cycle}.");

                $uploadResponse = $this->post('/api/images/upload', [
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

                $this->postJson('/api/moodboard/save', $afterUploadSavePayload)
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $loadAfterUpload = $this->getJson("/api/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectAfterUpload = $loadAfterUpload->json('data.objects.0');
                $this->assertSame($uploadedImageId, $objectAfterUpload['imageId'] ?? null, "imageId mismatch in cycle {$cycle}.");

                // Immediate re-save with loaded object to catch intermittent cleanup/overwrite issues.
                $this->postJson('/api/moodboard/save', [
                    'boardId' => $boardId,
                    'boardData' => [
                        'name' => "Screenshot cycle board {$cycle}",
                        'objects' => [$objectAfterUpload],
                    ],
                ])->assertOk()->assertJsonPath('success', true);

                $loadAfterResave = $this->getJson("/api/moodboard/{$boardId}")
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $objectAfterResave = $loadAfterResave->json('data.objects.0');
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

    private function assertUploadResponseContainsRequiredFields($response): string
    {
        $imageId = $response->json('data.imageId');
        $url = $response->json('data.url');
        $width = $response->json('data.width');
        $height = $response->json('data.height');
        $size = $response->json('data.size');

        $this->assertNotEmpty($imageId, 'Missing imageId in upload response.');
        $this->assertNotEmpty($url, 'Missing url in upload response.');
        $this->assertIsInt($width, 'Width must be integer.');
        $this->assertGreaterThan(0, $width, 'Width must be > 0.');
        $this->assertIsInt($height, 'Height must be integer.');
        $this->assertGreaterThan(0, $height, 'Height must be > 0.');
        $this->assertIsInt($size, 'Size must be integer.');
        $this->assertGreaterThan(0, $size, 'Size must be > 0.');

        return $imageId;
    }

    private function metadataPngUpload(string $filename): UploadedFile
    {
        $tmpFilePath = tempnam(sys_get_temp_dir(), 'mb_meta_png_');
        $this->assertNotFalse($tmpFilePath, 'Failed to allocate temporary file for metadata PNG.');

        $pngBytes = $this->buildPngWithTextChunk('MoodBoard', 'metadata payload for regression test');
        file_put_contents($tmpFilePath, $pngBytes);

        return new UploadedFile(
            $tmpFilePath,
            $filename,
            'image/png',
            null,
            true
        );
    }

    private function buildPngWithTextChunk(string $keyword, string $text): string
    {
        $signature = "\x89PNG\x0D\x0A\x1A\x0A";

        // 1x1 truecolor pixel (red)
        $ihdrData = pack('N', 1) . pack('N', 1) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
        $rawScanline = "\x00\xFF\x00\x00";
        $idatData = gzcompress($rawScanline, 9);
        $textData = $keyword . "\x00" . $text;

        $ihdrChunk = $this->pngChunk('IHDR', $ihdrData);
        $textChunk = $this->pngChunk('tEXt', $textData);
        $idatChunk = $this->pngChunk('IDAT', $idatData);
        $iendChunk = $this->pngChunk('IEND', '');

        return $signature . $ihdrChunk . $textChunk . $idatChunk . $iendChunk;
    }

    private function pngChunk(string $type, string $data): string
    {
        $length = pack('N', strlen($data));
        $crc = pack('N', crc32($type . $data));

        return $length . $type . $data . $crc;
    }

    private function ensureDiagnosticsDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function writeDiagnosticsJson(string $filename, array $payload): void
    {
        $fullPath = $this->diagnosticsDir . '/' . $filename;
        file_put_contents(
            $fullPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function screenshotDataUrl(): string
    {
        $pngBytes = $this->buildPngWithTextChunk('Screenshot', 'in-memory-canvas-capture');
        return 'data:image/png;base64,' . base64_encode($pngBytes);
    }

    private function assertFileResponseHasNonEmptyBody($response, string $errorMessage): array
    {
        $content = '';
        if (method_exists($response, 'streamedContent')) {
            $content = (string) $response->streamedContent();
        } elseif (method_exists($response, 'getContent')) {
            $content = (string) $response->getContent();
        }

        if ($content !== '') {
            return [
                'length' => strlen($content),
                'sha256' => hash('sha256', $content),
            ];
        }

        $contentLengthHeader = null;
        if (isset($response->baseResponse) && method_exists($response->baseResponse, 'headers')) {
            $contentLengthHeader = $response->baseResponse->headers->get('Content-Length');
        } elseif (isset($response->baseResponse->headers)) {
            $contentLengthHeader = $response->baseResponse->headers->get('Content-Length');
        }

        $contentLength = is_numeric($contentLengthHeader) ? (int) $contentLengthHeader : 0;
        $this->assertGreaterThan(0, $contentLength, $errorMessage);

        return [
            'length' => $contentLength,
            'sha256' => null,
        ];
    }
}
