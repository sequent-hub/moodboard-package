<?php

namespace Futurello\MoodBoard\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MoodboardHistory extends Model
{
    protected $table = 'moodboard_history';

    public $timestamps = false;

    protected $fillable = [
        'moodboard_id',
        'version',
        'state_json',
        'state_hash',
        'action_type',
        'created_at',
        'created_by',
    ];

    protected $casts = [
        'state_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('moodboard_history is append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('moodboard_history is append-only and cannot be deleted.');
        });
    }
}
