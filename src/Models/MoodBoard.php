<?php

namespace Futurello\MoodBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MoodBoard extends Model
{
    use HasFactory;

    protected $fillable = [
        'board_id',
        'name',
        'description',
        'data',
        'settings',
        'version',
        'last_saved_at'
    ];

    protected $casts = [
        'data' => 'array',
        'settings' => 'array',
        'last_saved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $dates = [
        'last_saved_at'
    ];

    protected $table = 'moodboards';
    /**
     * Автоматическое создание board_id при создании записи
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->board_id)) {
                // Генерируем короткий безопасный ID
                $model->board_id = static::generateShortId();
            }
            if (empty($model->last_saved_at)) {
                $model->last_saved_at = now();
            }
        });

        static::updating(function ($model) {
            $model->last_saved_at = now();
            $model->version++;
        });
    }

    /**
     * Генерация короткого безопасного ID
     */
    public static function generateShortId(): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';

        for ($i = 0; $i < 11; $i++) {
            $id .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Проверяем уникальность
        while (static::where('board_id', $id)->exists()) {
            $id = static::generateShortId();
        }

        return $id;
    }

    /**
     * Поиск по публичному ID
     */
    public static function findByBoardId(string $boardId)
    {
        return static::where('board_id', $boardId)->first();
    }

    /**
     * Создание или обновление доски
     */
    public static function createOrUpdateBoard(string $boardId, array $data, array $settings = null)
    {
        // Если boardId пустой или 'default', генерируем новый
        if (empty($boardId) || $boardId === 'default') {
            $boardId = static::generateShortId();
        }

        $board = static::findByBoardId($boardId);

        if ($board) {
            // Обновляем существующую доску
            $board->update([
                'data' => $data,
                'settings' => $settings ?? $board->settings,
            ]);
        } else {
            // Создаем новую доску с явным указанием board_id
            $board = static::create([
                'board_id' => $boardId,  // ✅ Явно указываем ID
                'name' => $data['name'] ?? 'Untitled Board',
                'description' => $data['description'] ?? null,
                'data' => array_merge([
                    'objects' => [],
                    'name' => $data['name'] ?? 'Untitled Board',
                    'description' => $data['description'] ?? null
                ], $data),
                'settings' => $settings ?? static::getDefaultSettings(),
            ]);
        }

        return $board;
    }

    /**
     * Настройки доски по умолчанию
     */
    public static function getDefaultSettings(): array
    {
        return [
            'backgroundColor' => '#F5F5F5',
            'grid' => [
                'type' => 'line',
                'size' => 20,
                'visible' => true,
                'color' => '#E0E0E0'
            ],
            'zoom' => [
                'min' => 0.1,
                'max' => 5.0,
                'default' => 1.0
            ],
            'canvas' => [
                'width' => 2000,
                'height' => 2000
            ]
        ];
    }

    /**
     * Получение полных данных для фронтенда
     */
    public function getFullData(): array
    {
        return [
            'id' => $this->board_id,
            'name' => $this->name,
            'description' => $this->description,
            'objects' => $this->data['objects'] ?? [],
            'settings' => $this->settings,
            'version' => $this->version,
            'created' => $this->created_at->toISOString(),
            'lastSaved' => $this->last_saved_at->toISOString(),
            'updated' => $this->updated_at->toISOString(),
        ];
    }

    /**
     * Статистика объектов на доске
     */
    public function getObjectStats(): array
    {
        $objects = $this->data['objects'] ?? [];

        $stats = [
            'total' => count($objects),
            'by_type' => []
        ];

        foreach ($objects as $object) {
            $type = $object['type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        }

        return $stats;
    }
}
