<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'status',
        'is_public',
        'max_players',
        'tick_rate',
        'width',
        'height',
        'host_player_id',
        'started_at',
        'finished_at',
        'winner_id',
        'game_state',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'game_state'  => 'array',
        'max_players' => 'integer',
        'tick_rate'   => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
        'is_public'   => 'boolean',
    ];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function matches()
    {
        return $this->hasMany(MatchRecord::class);
    }

    public function host()
    {
        return $this->belongsTo(Player::class, 'host_player_id');
    }

    public function winner()
    {
        return $this->belongsTo(Player::class, 'winner_id');
    }

    public function isFull(): bool
    {
        return $this->players()->count() >= $this->max_players;
    }

    public static function generateUniqueCode(): string
    {
        do {
            // Cryptographically secure uppercase alphanumeric 6-character code
            $code = strtoupper(Str::random(6));
            // Prevent visually confusing characters like 0, O, 1, I
            $code = str_replace(['0', 'O', '1', 'I'], ['X', 'Y', '8', '9'], $code);
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
