<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;

class FileController extends Controller
{
    /**
     * Загрузить файл
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240', // 10MB максимум
            'name' => 'sometimes|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $uploadedFile = $request->file('file');
            $originalName = $request->input('name', $uploadedFile->getClientOriginalName());

            // Генерируем уникальное имя файла
            $extension = $uploadedFile->getClientOriginalExtension();
            $filename = Str::random(40) . '.' . $extension;

            // Создаем хеш файла для дедупликации
            $hash = hash_file('sha256', $uploadedFile->getRealPath());

            // Проверяем, не существует ли уже такой файл
            $existingFile = File::where('hash', $hash)->first();
            if ($existingFile) {
                return response()->json([
                    'success' => true,
                    'message' => 'Файл уже существует',
                    'data' => [
                        'id' => $existingFile->id,
                        'name' => $existingFile->name,
                        'url' => $existingFile->url,
                        'size' => $existingFile->size,
                        'mime_type' => $existingFile->mime_type,
                        'formatted_size' => $existingFile->formatted_size
                    ]
                ]);
            }

            // Сохраняем файл
            $path = $uploadedFile->storeAs('files', $filename, 'public');

            // Создаем запись в БД
            $file = File::create([
                'name' => $originalName,
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $uploadedFile->getMimeType(),
                'size' => $uploadedFile->getSize(),
                'extension' => $extension,
                'hash' => $hash
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно загружен',
                'data' => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'url' => $file->url,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                    'formatted_size' => $file->formatted_size
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить информацию о файле
     */
    public function show($id)
    {
        try {
            $file = File::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'filename' => $file->filename,
                    'url' => $file->url,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                    'extension' => $file->extension,
                    'formatted_size' => $file->formatted_size,
                    'is_image' => $file->isImage(),
                    'created_at' => $file->created_at->toISOString(),
                    'updated_at' => $file->updated_at->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Файл не найден'
            ], 404);
        }
    }

    /**
     * Обновить информацию о файле
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = File::findOrFail($id);
            
            $file->update($request->only(['name']));

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно обновлен',
                'data' => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'url' => $file->url,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать файл
     */
    public function download($id)
    {
        try {
            $file = File::findOrFail($id);

            if (!Storage::disk('public')->exists($file->path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден на диске'
                ], 404);
            }

            return Storage::disk('public')->download($file->path, $file->name);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить файл
     */
    public function destroy($id)
    {
        try {
            $file = File::findOrFail($id);
            $file->delete();

            Log::info("File deleted: {$id}");

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно удален'
            ]);

        } catch (\Exception $e) {
            Log::error('File destroy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Очистка неиспользуемых файлов
     */
    public function cleanup()
    {
        try {
            // Удаляем файлы старше 30 дней
            $deletedCount = File::where('created_at', '<', now()->subDays(30))->delete();

            Log::info("File cleanup: deleted {$deletedCount} old files");

            return response()->json([
                'success' => true,
                'message' => "Очистка завершена. Удалено {$deletedCount} файлов"
            ]);

        } catch (\Exception $e) {
            Log::error('File cleanup error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки: ' . $e->getMessage()
            ], 500);
        }
    }
}
