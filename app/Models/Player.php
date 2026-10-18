<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'nickname',
        'token_hash',
        'color',
        'is_host',
        'is_ready',
        'is_alive',
        'score',
        'length',
        'rank',
        'last_seen_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'is_host' => 'boolean',
        'is_ready' => 'boolean',
        'is_alive' => 'boolean',
        'score' => 'integer',
        'length' => 'integer',
        'rank' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function generateCredentials(): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = static::hashToken($rawToken);

        return [
            'raw_token' => $rawToken,
            'token_hash' => $tokenHash,
        ];
    }
}
