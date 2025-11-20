<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Models\MoodBoard;
use Futurello\MoodBoard\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;

class MoodBoardController extends Controller
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

    /**
     * Сохранение данных в БД
     */
    public function save(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'boardId' => 'required',
            'boardData' => 'sometimes|array',
            'cardId' => 'sometimes', // Альтернативное поле для ID
            'data' => 'sometimes|array', // Альтернативное поле для данных
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные',
                'errors' => $validator->errors(),
                'received_data' => $request->all() // Для отладки
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Поддерживаем разные форматы данных от MoodBoard
            $boardId = (string) ($request->input('boardId') ?? $request->input('cardId') ?? 'default');
            $boardData = $request->input('boardData') ?? $request->input('data') ?? [];

            // Очищаем данные изображений от base64, оставляем только imageId
            $cleanedBoardData = $this->cleanImageData($boardData);
            
            // Создаем или обновляем доску
            $board = MoodBoard::createOrUpdateBoard($boardId, $cleanedBoardData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Данные успешно сохранены',
                'timestamp' => $board->last_saved_at->toISOString(),
                'version' => $board->version
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения данных'
            ], 500);
        }
    }

    /**
     * Загрузка данных из БД
     */
    public function load(string $boardId): JsonResponse
    {
        try {
            $board = MoodBoard::findByBoardId($boardId);

            if (!$board) {
                // Создаем новую доску
                $board = MoodBoard::create([
                    'board_id' => $boardId,
                    'name' => 'New Board',
                    'data' => [
                        'objects' => []
                    ],
                    'settings' => MoodBoard::getDefaultSettings()
                ]);

                $boardData = $board->getFullData();

                return response()->json([
                    'success' => true,
                    'data' => $boardData,
                    'message' => 'Создана новая доска'
                ]);
            }

            // Восстанавливаем URL изображений
            $boardData = $board->getFullData();
            $restoredData = $this->restoreImageUrls($boardData);

            return response()->json([
                'success' => true,
                'data' => $restoredData,
                'message' => 'Данные загружены'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки данных'
            ], 500);
        }
    }

    /**
     * Показать доску
     */
    public function show(string $boardId): JsonResponse
    {
        try {
            $board = MoodBoard::findByBoardId($boardId);

            if (!$board) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доска не найдена'
                ], 404);
            }

            $boardData = $board->getFullData();

            return response()->json([
                'success' => true,
                'data' => $boardData
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки данных'
            ], 500);
        }
    }

    /**
     * Список всех досок
     */
    public function index(): JsonResponse
    {
        try {
            $boards = MoodBoard::orderBy('updated_at', 'desc')->get();

            $boardsData = $boards->map(function ($board) {
                return [
                    'id' => $board->board_id,
                    'name' => $board->name,
                    'description' => $board->description,
                    'version' => $board->version,
                    'created' => $board->created_at->toISOString(),
                    'updated' => $board->updated_at->toISOString(),
                    'lastSaved' => $board->last_saved_at->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $boardsData
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки списка досок'
            ], 500);
        }
    }

    /**
     * Удаление доски
     */
    public function destroy(string $boardId): JsonResponse
    {
        try {
            $board = MoodBoard::findByBoardId($boardId);

            if (!$board) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доска не найдена'
                ], 404);
            }

            $board->delete();

            return response()->json([
                'success' => true,
                'message' => 'Доска успешно удалена'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления доски'
            ], 500);
        }
    }

    /**
     * Дублирование доски
     */
    public function duplicate(string $boardId): JsonResponse
    {
        try {
            $originalBoard = MoodBoard::findByBoardId($boardId);

            if (!$originalBoard) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доска не найдена'
                ], 404);
            }

            $newBoardId = MoodBoard::generateShortId();

            $duplicatedBoard = MoodBoard::create([
                'board_id' => $newBoardId,
                'name' => $originalBoard->name . ' (копия)',
                'description' => $originalBoard->description,
                'data' => $originalBoard->data,
                'settings' => $originalBoard->settings,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Доска успешно продублирована',
                'data' => [
                    'id' => $duplicatedBoard->board_id,
                    'name' => $duplicatedBoard->name
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка дублирования доски'
            ], 500);
        }
    }

    /**
     * Статистика изображений для доски
     */
    public function getImageStats(string $boardId): JsonResponse
    {
        try {
            $board = MoodBoard::findByBoardId($boardId);

            if (!$board) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доска не найдена'
                ], 404);
            }

            $stats = $board->getObjectStats();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики'
            ], 500);
        }
    }

    /**
     * Очистка данных изображений от base64
     */
    private function cleanImageData(array $boardData): array
    {
        if (isset($boardData['objects'])) {
            foreach ($boardData['objects'] as &$object) {
                if (isset($object['type']) && $object['type'] === 'image') {
                    // Оставляем только imageId, убираем base64
                    if (isset($object['imageId'])) {
                        unset($object['src']);
                        unset($object['base64']);
                    }
                }
            }
        }

        return $boardData;
    }

    /**
     * Восстановление URL изображений
     */
    private function restoreImageUrls(array $boardData): array
    {
        if (isset($boardData['objects'])) {
            foreach ($boardData['objects'] as &$object) {
                if (isset($object['type']) && $object['type'] === 'image') {
                    if (isset($object['imageId'])) {
                        $image = Image::find($object['imageId']);
                        if ($image) {
                            // Используем безопасный способ получения URL
                            $object['src'] = $this->getImageUrl($image->id);
                            
                            // Добавляем только дополнительную информацию об изображении, НЕ меняя пользовательские размеры
                            $object['name'] = $image->name;
                        } else {
                            // Если изображение не найдено, помечаем как недоступный
                            $object['src'] = null;
                            $object['error'] = 'Image not found';
                        }
                    }
                }
            }
        }

        return $boardData;
    }

    /**
     * Получение правильного URL изображения
     */
    private function getImageUrl(string $imageId): string
    {
        try {
            // Пытаемся использовать именованный маршрут
            return route('images.file', $imageId);
        } catch (\Exception $e) {
            // Если маршрут не найден, возвращаем базовый URL
            return url("/api/images/{$imageId}/file");
        }
    }
}
