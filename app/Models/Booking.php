<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'user_id', 'starts_at', 'ends_at', 'title'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Брони комнаты, пересекающиеся с интервалом. Полуинтервалы [start, end):
     * встречи «впритык» (10:00–11:00 и 11:00–12:00) не пересекаются.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, Room $room, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where('room_id', $room->id)
            ->where('starts_at', '<', $endsAt->toImmutable()->utc())
            ->where('ends_at', '>', $startsAt->toImmutable()->utc());
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
