<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;

class ImageController extends Controller
{
    /**
     * Handle OPTIONS requests for CORS
     */
    public function options()
    {
        return response()->json([], 200)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp,bmp|max:10240',
            'name' => 'sometimes|string|max:255',
            'width' => 'sometimes|integer|min:1',
            'height' => 'sometimes|integer|min:1'
        ]);

        try {
            $file = $request->file('image');

            if (!in_array($file->getClientMimeType(), [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
                'image/webp', 'image/bmp'
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неподдерживаемый тип файла'
                ], 422);
            }

            $extension = $file->getClientOriginalExtension();
            $filename = time() . '_' . Str::random(10) . '.' . $extension;
            $path = 'images/' . date('Y/m') . '/' . $filename;

            $imageInfo = getimagesize($file->getPathname());
            $width = $request->input('width', $imageInfo[0] ?? 100);
            $height = $request->input('height', $imageInfo[1] ?? 100);

            $cdnBaseUrl = trim((string) env('MOODBOARD_IMAGE_CDN_BASE_URL', ''));

            if ($cdnBaseUrl !== '') {
                $directory = dirname($path);
                if (!Storage::disk('s3')->exists($directory)) {
                    Storage::disk('s3')->makeDirectory($directory);
                }
                Storage::disk('s3')->put($path, file_get_contents($file));
                $url = $this->buildImageUrl($cdnBaseUrl, $path);
                Log::info('Image uploaded to object storage (CDN)', ['path' => $path, 'url' => $url]);
            } else {
                // CDN не настроен — сохраняем в public disk (storage/app/public),
                // файл доступен через симлинк public/storage/{path} (только для dev).
                Storage::disk('public')->put($path, file_get_contents($file));
                $url = url('storage/' . $path);
                Log::info('Image saved to local storage (CDN not configured)', ['path' => $path, 'url' => $url]);
            }

            $response = response()->json([
                'success' => true,
                'data' => [
                    'url' => $url,
                    'name' => $request->input('name', $file->getClientOriginalName()),
                    'width' => $width,
                    'height' => $height,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getClientMimeType(),
                ],
                'message' => 'Изображение успешно загружено'
            ]);

            // Добавляем CORS заголовки
            return $this->addCorsHeaders($response);

        } catch (\Exception $e) {
            Log::error('Image upload error: ' . $e->getMessage());

            $response = response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки: ' . $e->getMessage()
            ], 500);

            return $this->addCorsHeaders($response);
        }
    }

    public function show(string $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Not implemented in API v2',
        ], 501);
    }

    public function file(string $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Not implemented in API v2',
        ], 501);
    }

    public function destroy(string $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Not implemented in API v2',
        ], 501);
    }

    public function index()
    {
        return response()->json([
            'success' => false,
            'message' => 'Not implemented in API v2',
        ], 501);
    }

    public function bulkDelete(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Not implemented in API v2',
        ], 501);
    }

    /**
     * Добавление CORS заголовков к ответу
     */
    private function addCorsHeaders($response)
    {
        return $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
        ]);
    }

    /**
     * Build public URL for image response.
     * If CDN base URL is configured, returns CDN URL with object path.
     */
    private function buildImageUrl(string $cdnBaseUrl, string $objectPath): string
    {
        return rtrim($cdnBaseUrl, '/') . '/' . ltrim($objectPath, '/');
    }


}
