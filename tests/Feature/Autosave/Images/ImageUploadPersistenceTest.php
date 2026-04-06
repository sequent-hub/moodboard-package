<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Images;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;
use Illuminate\Http\UploadedFile;

class ImageUploadPersistenceTest extends AbstractAutosaveTestCase
{
    public function test_it_uploads_regular_and_metadata_png_and_files_are_accessible(): void
    {
        $regularUpload = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('regular.png', 120, 80),
            'name' => 'Regular PNG',
        ])->assertOk()->assertJsonPath('success', true);

        $regularUrl = $this->assertUploadResponseContainsRequiredFields($regularUpload);

        $metadataUpload = $this->post('/api/v2/images/upload', [
            'image' => $this->metadataPngUpload('metadata.png'),
            'name' => 'Metadata PNG',
        ])->assertOk()->assertJsonPath('success', true);

        $metadataUrl = $this->assertUploadResponseContainsRequiredFields($metadataUpload);
        $this->assertNotEmpty($regularUrl);
        $this->assertNotEmpty($metadataUrl);
    }

    public function test_it_saves_and_loads_image_object_with_src(): void
    {
        $src = $this->post('/api/v2/images/upload', [
            'image' => $this->metadataPngUpload('single-board-metadata.png'),
            'name' => 'Single board metadata PNG',
        ])->assertOk()->json('data.url');

        $this->assertNotEmpty($src);

        $boardId = 'board-image-single';
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Image board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'image-obj-1',
                        'type' => 'image',
                        'src' => $src,
                        'position' => ['x' => 10, 'y' => 20],
                        'width' => 320,
                        'height' => 240,
                        'properties' => ['name' => 'single image object'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $loadResponse = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $objects = $loadResponse->json('data.state.objects');
        $this->assertCount(1, $objects);
        $this->assertSame('image', $objects[0]['type']);
        $this->assertSame($src, $objects[0]['src'] ?? null);
    }

    public function test_it_persists_multiple_images_in_one_board_including_metadata_png(): void
    {
        $regularSrc = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('multi-regular.png', 100, 60),
            'name' => 'Multi Regular',
        ])->assertOk()->json('data.url');

        $metadataSrc = $this->post('/api/v2/images/upload', [
            'image' => $this->metadataPngUpload('multi-metadata.png'),
            'name' => 'Multi Metadata',
        ])->assertOk()->json('data.url');

        $this->assertNotEmpty($regularSrc);
        $this->assertNotEmpty($metadataSrc);

        $boardId = 'board-image-multi';
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Multiple images board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'img-obj-1',
                        'type' => 'image',
                        'src' => $regularSrc,
                        'position' => ['x' => 0, 'y' => 0],
                        'width' => 100,
                        'height' => 60,
                        'properties' => ['name' => 'regular'],
                    ],
                    [
                        'id' => 'img-obj-2',
                        'type' => 'image',
                        'src' => $metadataSrc,
                        'position' => ['x' => 120, 'y' => 0],
                        'width' => 1,
                        'height' => 1,
                        'properties' => ['name' => 'metadata'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $loadResponse = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $objects = $loadResponse->json('data.state.objects');
        $this->assertCount(2, $objects);

        $urls = array_column($objects, 'src');
        $this->assertContains($regularSrc, $urls);
        $this->assertContains($metadataSrc, $urls);
    }

    public function test_it_returns_validation_errors_for_upload_issues(): void
    {
        $this->post('/api/v2/images/upload', [], [
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $unsupportedMime = UploadedFile::fake()->create('not-supported.svg', 10, 'image/svg+xml');
        $this->post('/api/v2/images/upload', [
            'image' => $unsupportedMime,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }
}
