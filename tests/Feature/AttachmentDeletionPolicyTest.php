<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Models\File;
use Futurello\MoodBoard\Tests\TestCase;
use LogicException;

class AttachmentDeletionPolicyTest extends TestCase
{
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
