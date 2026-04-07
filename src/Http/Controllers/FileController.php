<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

            // Сохраняем файл
            $path = $uploadedFile->storeAs('files', $filename, 's3');
            $url = Storage::disk('s3')->url($path);

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно загружен',
                'data' => [
                    'name' => $originalName,
                    'url' => $url,
                    'size' => $uploadedFile->getSize(),
                    'mime_type' => $uploadedFile->getMimeType(),
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
            return response()->json([
                'success' => false,
                'message' => 'File endpoints by id are deprecated in API v2. Use file url from moodboard state.',
            ], 410);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'File endpoints by id are deprecated in API v2. Use file url from moodboard state.',
            ], 410);
        }
    }

    /**
     * Обновить информацию о файле
     */
    public function update(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'File endpoints by id are deprecated in API v2. Use file url from moodboard state.',
        ], 410);
    }

    /**
     * Скачать файл
     */
    public function download($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'File endpoints by id are deprecated in API v2. Use file url from moodboard state.',
        ], 410);
    }

    /**
     * Удалить файл
     */
    public function destroy($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'File endpoints by id are deprecated in API v2. Use file url from moodboard state.',
        ], 410);
    }

}
