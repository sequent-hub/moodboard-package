<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image as InterventionImage;
use Illuminate\Routing\Controller;

class ImageController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240', // 10MB макс
            'name' => 'string|max:255',
            'width' => 'integer|min:1',
            'height' => 'integer|min:1'
        ]);

        try {
            $file = $request->file('image');
            $hash = md5_file($file->getPathname());

            // Проверяем дубликаты
            $existingImage = Image::where('hash', $hash)->first();
            if ($existingImage) {
                Log::info("Reusing existing image: {$existingImage->id}");

                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $existingImage->id,
                        'url' => route('images.file', $existingImage->id),
                        'name' => $existingImage->name,
                        'width' => $existingImage->width,
                        'height' => $existingImage->height,
                        'size' => $existingImage->size
                    ],
                    'message' => 'Использовано существующее изображение'
                ]);
            }

            // Генерируем уникальное имя файла
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '_' . Str::random(10) . '.' . $extension;
            $path = 'images/' . date('Y/m') . '/' . $filename; // Организуем по папкам год/месяц

            // Создаем директорию если не существует
            $directory = dirname($path);
            if (!Storage::exists($directory)) {
                Storage::makeDirectory($directory);
            }

            // Сохраняем файл
            Storage::put($path, file_get_contents($file));

            // Получаем размеры изображения
            $imageInfo = getimagesize($file->getPathname());
            $width = $request->input('width', $imageInfo[0] ?? 100);
            $height = $request->input('height', $imageInfo[1] ?? 100);

            // Сохраняем в БД
            $image = Image::create([
                'name' => $request->input('name', $file->getClientOriginalName()),
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'hash' => $hash
            ]);

            Log::info("Image uploaded: {$image->id} ({$image->name})");

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $image->id,
                    'url' => route('images.file', $image->id),
                    'name' => $image->name,
                    'width' => $image->width,
                    'height' => $image->height,
                    'size' => $image->size
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Image upload error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $image = Image::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $image->id,
                    'name' => $image->name,
                    'original_name' => $image->original_name,
                    'url' => route('images.file', $image->id),
                    'width' => $image->width,
                    'height' => $image->height,
                    'size' => $image->size,
                    'mime_type' => $image->mime_type,
                    'created_at' => $image->created_at->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Image show error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Изображение не найдено'
            ], 404);
        }
    }

    public function file(string $id)
    {
        try {
            $image = Image::findOrFail($id);
            
            if (!Storage::exists($image->path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден'
                ], 404);
            }

            return Storage::response($image->path, $image->original_name, [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000'
            ]);

        } catch (\Exception $e) {
            Log::error('Image file error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения файла'
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $image = Image::findOrFail($id);
            $image->delete();

            Log::info("Image deleted: {$id}");

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            Log::error('Image destroy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения'
            ], 500);
        }
    }

    public function index()
    {
        try {
            $images = Image::orderBy('created_at', 'desc')->get();

            $imagesData = $images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'name' => $image->name,
                    'url' => route('images.file', $image->id),
                    'width' => $image->width,
                    'height' => $image->height,
                    'size' => $image->size,
                    'created_at' => $image->created_at->toISOString()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $imagesData
            ]);

        } catch (\Exception $e) {
            Log::error('Image index error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки списка изображений'
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string|exists:images,id'
        ]);

        try {
            $ids = $request->input('ids');
            $deletedCount = Image::whereIn('id', $ids)->delete();

            Log::info("Bulk deleted {$deletedCount} images");

            return response()->json([
                'success' => true,
                'message' => "Удалено {$deletedCount} изображений"
            ]);

        } catch (\Exception $e) {
            Log::error('Image bulk delete error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового удаления'
            ], 500);
        }
    }

    public function cleanup()
    {
        try {
            // Находим изображения, которые не используются в moodboard
            $usedImageIds = collect();
            
            // Здесь должна быть логика поиска используемых изображений
            // Пока просто удаляем старые изображения (старше 30 дней)
            
            $deletedCount = Image::where('created_at', '<', now()->subDays(30))->delete();

            Log::info("Cleanup: deleted {$deletedCount} old images");

            return response()->json([
                'success' => true,
                'message' => "Очистка завершена. Удалено {$deletedCount} изображений"
            ]);

        } catch (\Exception $e) {
            Log::error('Image cleanup error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки'
            ], 500);
        }
    }
}
