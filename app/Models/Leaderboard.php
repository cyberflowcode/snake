<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leaderboard extends Model
{
    use HasFactory;

    protected $table = 'leaderboards';

    protected $fillable = [
        'player_nickname',
        'high_score',
        'wins_count',
        'games_played',
    ];

    protected $casts = [
        'high_score' => 'integer',
        'wins_count' => 'integer',
        'games_played' => 'integer',
    ];

    public static function recordGame(string $nickname, int $score, bool $isWinner): void
    {
        $entry = static::firstOrCreate(
            ['player_nickname' => $nickname],
            ['high_score' => $score, 'wins_count' => $isWinner ? 1 : 0, 'games_played' => 1]
        );

        if (!$entry->wasRecentlyCreated) {
            $entry->games_played += 1;
            if ($isWinner) {
                $entry->wins_count += 1;
            }
            if ($score > $entry->high_score) {
                $entry->high_score = $score;
            }
            $entry->save();
        }
    }
}
