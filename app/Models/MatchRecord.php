<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchRecord extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'room_id',
        'winner_nickname',
        'winner_score',
        'duration_seconds',
        'results_json',
    ];

    protected $casts = [
        'winner_score' => 'integer',
        'duration_seconds' => 'integer',
        'results_json' => 'array',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
