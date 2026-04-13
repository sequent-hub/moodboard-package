<?php

namespace Futurello\MoodBoard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LegacyAssetMigrator
{
    private string $cdnBaseUrl;
    private string $localStoragePath;

    private int $uploadedToS3 = 0;
    private int $markedAsPlaceholder = 0;
    private int $normalizedToCdn = 0;
    private int $skippedUnchanged = 0;
    private array $errors = [];

    public function __construct(string $localStoragePath)
    {
        $this->cdnBaseUrl = trim((string) env('MOODBOARD_IMAGE_CDN_BASE_URL', ''));
        $this->localStoragePath = rtrim($localStoragePath, '/');
    }

    public function hasRequiredCdnConfig(): bool
    {
        return $this->cdnBaseUrl !== '';
    }

    public function resetCounters(): void
    {
        $this->uploadedToS3 = 0;
        $this->markedAsPlaceholder = 0;
        $this->normalizedToCdn = 0;
        $this->skippedUnchanged = 0;
        $this->errors = [];
    }

    public function getStats(): array
    {
        return [
            'uploaded_to_s3' => $this->uploadedToS3,
            'marked_as_placeholder' => $this->markedAsPlaceholder,
            'normalized_to_cdn' => $this->normalizedToCdn,
            'skipped_unchanged' => $this->skippedUnchanged,
            'errors' => $this->errors,
        ];
    }

    /**
     * Process all objects in a moodboard state, migrating image/file sources.
     *
     * @param array $state  The moodboard state (with 'objects' key)
     * @param bool  $dryRun If true, do not upload to S3, just analyze
     * @return array The processed state
     */
    public function processState(array $state, bool $dryRun): array
    {
        if (!isset($state['objects']) || !is_array($state['objects'])) {
            return $state;
        }

        foreach ($state['objects'] as &$object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;

            if ($type === 'image') {
                $object = $this->processImageObject($object, $dryRun);
            } elseif ($type === 'file') {
                $object = $this->processFileObject($object, $dryRun);
            }
        }

        unset($object);
        return $state;
    }

    private function processImageObject(array $object, bool $dryRun): array
    {
        $src = $object['src'] ?? null;
        $imageId = $object['imageId'] ?? null;

        if ($this->isAlreadyCdnUrl($src)) {
            $object['src'] = $this->normalizeToCdn($src);
            $this->normalizedToCdn++;
            return $this->cleanImageObject($object);
        }

        if ($imageId !== null && is_string($imageId) && $imageId !== '') {
            $object['src'] = $this->resolveImageById($imageId, $dryRun);
            return $this->cleanImageObject($object);
        }

        if (is_string($src) && $src !== '') {
            $object['src'] = $this->resolveSrc($src, $dryRun);
            return $this->cleanImageObject($object);
        }

        $object['src'] = '';
        $this->markedAsPlaceholder++;
        return $this->cleanImageObject($object);
    }

    private function processFileObject(array $object, bool $dryRun): array
    {
        $fileId = $object['fileId'] ?? null;
        $src = $object['src'] ?? null;

        if ($this->isAlreadyCdnUrl($src)) {
            $object['src'] = $this->normalizeToCdn($src);
            $this->normalizedToCdn++;
            return $object;
        }

        if ($fileId !== null) {
            $object['src'] = $this->resolveFileById($fileId, $dryRun);
            return $object;
        }

        if (is_string($src) && $src !== '') {
            $object['src'] = $this->resolveSrc($src, $dryRun);
            return $object;
        }

        $object['src'] = '';
        $this->markedAsPlaceholder++;
        return $object;
    }

    private function resolveImageById(string $imageId, bool $dryRun): string
    {
        $row = DB::table('images')->where('id', $imageId)->first();

        if (!$row) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "Image not found in DB: imageId={$imageId}";
            return '';
        }

        $localPath = $this->resolveLocalPath((string) $row->path);

        if ($localPath === null) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "Image file missing on disk: path={$row->path}, imageId={$imageId}";
            return '';
        }

        return $this->uploadLocalFileToS3($localPath, $row->path, $dryRun);
    }

    private function resolveFileById($fileId, bool $dryRun): string
    {
        $row = DB::table('files')->where('id', $fileId)->first();

        if (!$row) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "File not found in DB: fileId={$fileId}";
            return '';
        }

        $localPath = $this->resolveLocalPath((string) $row->path);

        if ($localPath === null) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "File missing on disk: path={$row->path}, fileId={$fileId}";
            return '';
        }

        return $this->uploadLocalFileToS3($localPath, $row->path, $dryRun);
    }

    private function resolveSrc(string $src, bool $dryRun): string
    {
        if (str_starts_with($src, 'blob:')) {
            $this->markedAsPlaceholder++;
            return '';
        }

        if (str_starts_with($src, 'data:')) {
            return $this->resolveBase64($src, $dryRun);
        }

        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            $objectPath = $this->extractObjectPath($src);
            if ($objectPath !== null) {
                $this->normalizedToCdn++;
                return $this->normalizeToCdn($src);
            }

            return $this->downloadExternalAndUpload($src, $dryRun);
        }

        $objectPath = $this->extractObjectPath($src);
        if ($objectPath !== null) {
            $this->normalizedToCdn++;
            return $this->normalizeToCdn($src);
        }

        $this->markedAsPlaceholder++;
        $this->errors[] = "Unrecognized src format: " . Str::limit($src, 100);
        return '';
    }

    private function resolveBase64(string $src, bool $dryRun): string
    {
        if (!preg_match('#^data:([a-zA-Z0-9/+.-]+);base64,(.+)$#s', $src, $matches)) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "Invalid base64 src format";
            return '';
        }

        $mimeType = $matches[1];
        $base64Data = $matches[2];

        $decoded = base64_decode($base64Data, true);
        if ($decoded === false) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "Failed to decode base64 data";
            return '';
        }

        $extension = $this->mimeToExtension($mimeType);
        $s3Path = 'images/' . date('Y/m') . '/' . time() . '_' . Str::random(10) . '.' . $extension;

        if ($dryRun) {
            $this->uploadedToS3++;
            return '[dry-run] would upload base64 → ' . $s3Path;
        }

        try {
            Storage::disk('s3')->put($s3Path, $decoded);
            $this->uploadedToS3++;
            return $this->buildCdnUrl($s3Path);
        } catch (\Throwable $e) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "S3 upload failed for base64: " . $e->getMessage();
            return '';
        }
    }

    private function downloadExternalAndUpload(string $url, bool $dryRun): string
    {
        if ($dryRun) {
            $this->uploadedToS3++;
            return '[dry-run] would download and upload: ' . Str::limit($url, 100);
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'MoodboardMigrator/1.0',
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                $this->markedAsPlaceholder++;
                $this->errors[] = "Failed to download external URL: " . Str::limit($url, 200);
                return '';
            }

            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
            if ($extension === '' || strlen($extension) > 10) {
                $extension = 'jpg';
            }

            $s3Path = 'images/' . date('Y/m') . '/' . time() . '_' . Str::random(10) . '.' . $extension;
            Storage::disk('s3')->put($s3Path, $content);
            $this->uploadedToS3++;
            return $this->buildCdnUrl($s3Path);
        } catch (\Throwable $e) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "External download/upload failed: " . $e->getMessage() . " URL: " . Str::limit($url, 200);
            return '';
        }
    }

    private function uploadLocalFileToS3(string $localPath, string $relativePath, bool $dryRun): string
    {
        if ($dryRun) {
            $this->uploadedToS3++;
            return '[dry-run] would upload: ' . $relativePath;
        }

        try {
            $content = file_get_contents($localPath);
            if ($content === false) {
                $this->markedAsPlaceholder++;
                $this->errors[] = "Cannot read local file: {$localPath}";
                return '';
            }

            $s3Path = $relativePath;
            if (!Storage::disk('s3')->exists($s3Path)) {
                Storage::disk('s3')->put($s3Path, $content);
            }

            $this->uploadedToS3++;
            return $this->buildCdnUrl($s3Path);
        } catch (\Throwable $e) {
            $this->markedAsPlaceholder++;
            $this->errors[] = "S3 upload failed for {$relativePath}: " . $e->getMessage();
            return '';
        }
    }

    private function resolveLocalPath(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/');

        $candidates = [
            $this->localStoragePath . '/' . $relativePath,
        ];

        // Legacy files table points to files/*, but real files are stored under storage/app/public/files/*.
        if (str_starts_with($relativePath, 'files/')) {
            $candidates[] = $this->localStoragePath . '/public/' . $relativePath;
        }

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isAlreadyCdnUrl(?string $src): bool
    {
        if ($src === null || $src === '') {
            return false;
        }

        if ($this->extractObjectPath($src) !== null) {
            if (!str_starts_with($src, 'data:') && !str_starts_with($src, 'blob:')) {
                return true;
            }
        }

        return false;
    }

    private function extractObjectPath(string $src): ?string
    {
        $directPath = ltrim($src, '/');
        if (str_starts_with($directPath, 'images/') || str_starts_with($directPath, 'files/')) {
            return $directPath;
        }

        $path = parse_url($src, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $imagesPrefixPosition = strpos($path, '/images/');
        if ($imagesPrefixPosition !== false) {
            return ltrim(substr($path, $imagesPrefixPosition), '/');
        }

        $filesPrefixPosition = strpos($path, '/files/');
        if ($filesPrefixPosition !== false) {
            return ltrim(substr($path, $filesPrefixPosition), '/');
        }

        return null;
    }

    private function normalizeToCdn(string $src): string
    {
        if ($this->cdnBaseUrl === '') {
            throw new \RuntimeException('CDN URL is not configured for moodboard asset migration.');
        }

        $objectPath = $this->extractObjectPath($src);
        if ($objectPath === null) {
            return $src;
        }

        return rtrim($this->cdnBaseUrl, '/') . '/' . ltrim($objectPath, '/');
    }

    private function buildCdnUrl(string $s3Path): string
    {
        if ($this->cdnBaseUrl === '') {
            throw new \RuntimeException('CDN URL is not configured for moodboard asset migration.');
        }

        return rtrim($this->cdnBaseUrl, '/') . '/' . ltrim($s3Path, '/');
    }

    private function cleanImageObject(array $object): array
    {
        $allowedKeys = [
            'id',
            'type',
            'src',
            'position',
            'width',
            'height',
            'properties',
            'transform',
            'created',
        ];

        return array_intersect_key($object, array_flip($allowedKeys));
    }

    private function mimeToExtension(string $mimeType): string
    {
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
        ];

        return $map[$mimeType] ?? 'png';
    }
}
