<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Models\File;
use Futurello\MoodBoard\Models\Image;
use Futurello\MoodBoard\Tests\TestCase;
use LogicException;

class AttachmentDeletionPolicyTest extends TestCase
{
    public function test_image_delete_is_blocked_by_policy(): void
    {
        $image = Image::create([
            'name' => 'policy-image',
            'original_name' => 'policy-image.png',
            'path' => 'images/2026/01/policy-image.png',
            'mime_type' => 'image/png',
            'size' => 1,
            'width' => 1,
            'height' => 1,
            'hash' => 'hash-policy-image',
        ]);

        $this->expectException(LogicException::class);
        $image->delete();
    }

    public function test_file_delete_is_blocked_by_policy(): void
    {
        $file = File::create([
            'name' => 'policy-file',
            'filename' => 'policy-file.txt',
            'path' => 'files/policy-file.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'extension' => 'txt',
            'hash' => 'hash-policy-file',
        ]);

        $this->expectException(LogicException::class);
        $file->delete();
    }
}
