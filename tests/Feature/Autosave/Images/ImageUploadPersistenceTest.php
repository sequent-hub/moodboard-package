<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Images;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;
use Illuminate\Http\UploadedFile;

class ImageUploadPersistenceTest extends AbstractAutosaveTestCase
{
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

    public function test_it_returns_validation_errors_for_upload_issues(): void
    {
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
}
