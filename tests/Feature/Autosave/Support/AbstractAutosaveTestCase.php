<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Support;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class AbstractAutosaveTestCase extends TestCase
{
    protected string $diagnosticsRunId;
    protected string $diagnosticsDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MB_PERSISTENCE_REAL_DISK') !== '1') {
            Storage::fake('local');
        }

        $this->diagnosticsRunId = 'run_' . date('Ymd_His') . '_' . substr(md5((string) microtime(true)), 0, 8);
        $this->diagnosticsDir = $this->testsRootPath() . '/_artifacts/image-persistence/' . $this->diagnosticsRunId;
        $this->ensureDiagnosticsDirectoryExists($this->diagnosticsDir);
    }

    protected function assertUploadResponseContainsRequiredFields($response): string
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

    protected function metadataPngUpload(string $filename): UploadedFile
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

    protected function screenshotDataUrl(): string
    {
        $pngBytes = $this->buildPngWithTextChunk('Screenshot', 'in-memory-canvas-capture');
        return 'data:image/png;base64,' . base64_encode($pngBytes);
    }

    protected function assertFileResponseHasNonEmptyBody($response, string $errorMessage): array
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

    protected function writeDiagnosticsJson(string $filename, array $payload): void
    {
        $fullPath = $this->diagnosticsDir . '/' . $filename;
        file_put_contents(
            $fullPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function buildPngWithTextChunk(string $keyword, string $text): string
    {
        $signature = "\x89PNG\x0D\x0A\x1A\x0A";

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

    private function testsRootPath(): string
    {
        return dirname(__DIR__, 3);
    }
}
