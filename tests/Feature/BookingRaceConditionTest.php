<?php

namespace Tests\Feature;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Эмуляция гонки: параллельный запрос занимает слот уже после того,
     * как FormRequest прошёл валидацию. Пересечение проверяется повторно
     * внутри транзакции под блокировкой комнаты, поэтому вторая бронь не создаётся.
     */
    public function test_rejects_booking_when_slot_is_taken_after_validation(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->app->afterResolving(StoreBookingRequest::class, function () use ($room): void {
            Booking::factory()->for($room)->create([
                'starts_at' => CarbonImmutable::parse('2026-12-01T07:00:00Z'),
                'ends_at' => CarbonImmutable::parse('2026-12-01T08:00:00Z'),
            ]);
        });

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Встреча',
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'Комната уже занята в это время.']);

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Настоящую параллельность в одном процессе не воспроизвести, поэтому
     * проверяем, что store действительно берёт блокировку строки комнаты.
     */
    public function test_locks_room_row_while_creating_booking(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $executedQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$executedQueries): void {
            $executedQueries[] = $query->sql;
        });

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Встреча',
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ])->assertCreated();

        $roomLockQueries = array_filter(
            $executedQueries,
            fn (string $sql): bool => str_contains($sql, 'from "rooms"') && str_ends_with($sql, 'for update'),
        );

        $this->assertNotEmpty($roomLockQueries, 'store должен блокировать строку комнаты через SELECT ... FOR UPDATE');
    }
}
