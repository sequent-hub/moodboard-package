<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Models\MoodBoard;
use Futurello\MoodBoard\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

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
        $requestId = (string) Str::uuid();
        // Логируем все входящие данные для отладки
        \Log::info("MoodBoard save request received", [
            'req' => $requestId,
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
                'req' => $requestId,
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
                'req' => $requestId,
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

            // Снимок размеров изображений до очистки
            \Log::info("MoodBoard save snapshot (pre-clean)", [
                'req' => $requestId,
                'boardId' => $boardId,
                'images' => $this->snapshotImageObjects($boardData['objects'] ?? [])
            ]);

            // Очищаем данные изображений от base64, оставляем только imageId
            $cleanedBoardData = $this->cleanImageData($boardData);
            
            \Log::info("Cleaned board data", [
                'req' => $requestId,
                'cleanedData' => $cleanedBoardData,
                'images' => $this->snapshotImageObjects($cleanedBoardData['objects'] ?? [])
            ]);

            // Создаем или обновляем доску
            \Log::info("Calling createOrUpdateBoard", [
                'req' => $requestId,
                'boardId' => $boardId
            ]);
            $board = MoodBoard::createOrUpdateBoard($boardId, $cleanedBoardData);
            
            \Log::info("Board created/updated", [
                'req' => $requestId,
                'board' => $board,
                'board_id' => $board->board_id ?? 'null',
                'board_exists' => $board ? 'yes' : 'no'
            ]);

            // Снимок размеров изображений после сохранения (из БД)
            try {
                $persisted = $board->data ?? [];
                \Log::info("MoodBoard save snapshot (persisted)", [
                    'req' => $requestId,
                    'boardId' => $boardId,
                    'images' => $this->snapshotImageObjects($persisted['objects'] ?? [])
                ]);
            } catch (\Throwable $t) {
                \Log::warning("MoodBoard save snapshot (persisted) error", [
                    'req' => $requestId,
                    'message' => $t->getMessage()
                ]);
            }

            DB::commit();

            \Log::info("MoodBoard saved: {$boardId} (version {$board->version})", [
                'req' => $requestId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Данные успешно сохранены',
                'timestamp' => $board->last_saved_at->toISOString(),
                'version' => $board->version
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('MoodBoard save error: ' . $e->getMessage(), [
                'req' => $requestId,
                'trace' => $e->getTraceAsString()
            ]);

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
        $requestId = (string) Str::uuid();
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
            \Log::info("MoodBoard load snapshot (pre-restore)", [
                'req' => $requestId,
                'boardId' => $boardId,
                'images' => $this->snapshotImageObjects($boardData['objects'] ?? [])
            ]);
            $restoredData = $this->restoreImageUrls($boardData, $requestId);
            \Log::info("MoodBoard load snapshot (post-restore)", [
                'req' => $requestId,
                'boardId' => $boardId,
                'images' => $this->snapshotImageObjects($restoredData['objects'] ?? [])
            ]);

            return response()->json([
                'success' => true,
                'data' => $restoredData,
                'message' => 'Данные загружены'
            ]);

        } catch (\Exception $e) {
            \Log::error('MoodBoard load error: ' . $e->getMessage(), [
                'req' => $requestId,
                'trace' => $e->getTraceAsString()
            ]);

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
        $requestId = (string) Str::uuid();
        try {
            \Log::info("MoodBoard show called", [
                'req' => $requestId,
                'boardId' => $boardId,
                'method' => request()->getMethod(),
                'url' => request()->fullUrl()
            ]);

            $board = MoodBoard::findByBoardId($boardId);

            if (!$board) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доска не найдена'
                ], 404);
            }

            $boardData = $board->getFullData();

            // Диагностический снимок размеров до восстановления URL (возврат остаётся без изменений)
            \Log::info("MoodBoard show snapshot (pre-restore)", [
                'req' => $requestId,
                'boardId' => $boardId,
                'images' => $this->snapshotImageObjects($boardData['objects'] ?? [])
            ]);
            // Запускаем восстановление URL и проверку перезаписи в логах, но не изменяем выдачу
            try {
                $this->restoreImageUrls($boardData, $requestId);
            } catch (\Throwable $t) {
                \Log::warning("MoodBoard show restoreImageUrls logging attempt failed", [
                    'req' => $requestId,
                    'message' => $t->getMessage()
                ]);
            }

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
    private function restoreImageUrls(array $boardData, ?string $requestId = null): array
    {
        if (isset($boardData['objects'])) {
            foreach ($boardData['objects'] as &$object) {
                if (isset($object['type']) && $object['type'] === 'image') {
                    if (isset($object['imageId'])) {
                        $image = Image::find($object['imageId']);
                        if ($image) {
                            $beforeWidth = $object['width'] ?? null;
                            $beforeHeight = $object['height'] ?? null;
                            // Используем безопасный способ получения URL
                            $object['src'] = $this->getImageUrl($image->id);
                            
                            // Добавляем только дополнительную информацию об изображении, НЕ меняя пользовательские размеры
                            $object['name'] = $image->name;

                            if ($requestId !== null) {
                                $differsFromIntrinsic = ($beforeWidth !== null || $beforeHeight !== null)
                                    && (($beforeWidth !== $image->width) || ($beforeHeight !== $image->height));
                                if ($differsFromIntrinsic) {
                                    \Log::info("MoodBoard restore overwrite skipped", [
                                        'req' => $requestId,
                                        'imageId' => $image->id,
                                        'before' => ['width' => $beforeWidth, 'height' => $beforeHeight],
                                        'from_image' => ['width' => $image->width, 'height' => $image->height]
                                    ]);
                                }
                            }
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

    /**
     * Снимок объектов изображений: индекс, imageId, размеры, масштаб/трансформации (если есть)
     */
    private function snapshotImageObjects(array $objects): array
    {
        $result = [];
        foreach ($objects as $index => $object) {
            if (($object['type'] ?? null) !== 'image') {
                continue;
            }
            $entry = [
                'index' => $index,
                'imageId' => $object['imageId'] ?? null,
            ];
            if (array_key_exists('width', $object)) {
                $entry['width'] = $object['width'];
            }
            if (array_key_exists('height', $object)) {
                $entry['height'] = $object['height'];
            }
            if (array_key_exists('scale', $object)) {
                $entry['scale'] = $object['scale'];
            }
            if (array_key_exists('transform', $object)) {
                $entry['transform'] = $object['transform'];
            }
            $result[] = $entry;
        }
        return $result;
    }
}
