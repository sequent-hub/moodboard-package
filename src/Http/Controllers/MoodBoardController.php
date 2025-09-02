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
        // Логируем все входящие данные для отладки
        \Log::info("MoodBoard save request received", [
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
            'url' => $request->url(),
            'all_data' => $request->all(),
            'json_data' => $request->json()->all() ?? 'no json data'
        ]);

        $validator = Validator::make($request->all(), [
            'boardId' => 'required',
            'boardData' => 'sometimes|array',
            'cardId' => 'sometimes', // Альтернативное поле для ID
            'data' => 'sometimes|array', // Альтернативное поле для данных
        ]);

        if ($validator->fails()) {
            \Log::error("MoodBoard validation failed", [
                'errors' => $validator->errors(),
                'request_data' => $request->all()
            ]);
            
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

            \Log::info("MoodBoard save attempt", [
                'boardId' => $boardId,
                'boardId_type' => gettype($request->input('boardId')),
                'boardId_converted' => $boardId,
                'boardData' => $boardData,
                'request_all' => $request->all(),
                'has_boardId' => $request->has('boardId'),
                'has_cardId' => $request->has('cardId'),
                'has_boardData' => $request->has('boardData'),
                'has_data' => $request->has('data')
            ]);

            // Очищаем данные изображений от base64, оставляем только imageId
            $cleanedBoardData = $this->cleanImageData($boardData);
            
            \Log::info("Cleaned board data", ['cleanedData' => $cleanedBoardData]);

            // Создаем или обновляем доску
            \Log::info("Calling createOrUpdateBoard", ['boardId' => $boardId]);
            $board = MoodBoard::createOrUpdateBoard($boardId, $cleanedBoardData);
            
            \Log::info("Board created/updated", [
                'board' => $board,
                'board_id' => $board->board_id ?? 'null',
                'board_exists' => $board ? 'yes' : 'no'
            ]);

            DB::commit();

            \Log::info("MoodBoard saved: {$boardId} (version {$board->version})");

            return response()->json([
                'success' => true,
                'message' => 'Данные успешно сохранены',
                'timestamp' => $board->last_saved_at->toISOString(),
                'version' => $board->version
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('MoodBoard save error: ' . $e->getMessage());

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
            \Log::error('MoodBoard load error: ' . $e->getMessage());

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
            \Log::error('MoodBoard show error: ' . $e->getMessage());

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
            \Log::error('MoodBoard index error: ' . $e->getMessage());

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
            \Log::error('MoodBoard destroy error: ' . $e->getMessage());

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
            \Log::error('MoodBoard duplicate error: ' . $e->getMessage());

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
            \Log::error('MoodBoard image stats error: ' . $e->getMessage());

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
                            
                            // Добавляем дополнительную информацию об изображении
                            $object['width'] = $image->width;
                            $object['height'] = $image->height;
                            $object['name'] = $image->name;
                        } else {
                            // Если изображение не найдено, логируем это
                            \Log::warning("Image not found for imageId: {$object['imageId']}");
                            // Удаляем объект или помечаем как недоступный
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
            \Log::warning("Route 'images.file' not found, using fallback URL for image: {$imageId}");
            return url("/api/images/{$imageId}/file");
        }
    }
}
