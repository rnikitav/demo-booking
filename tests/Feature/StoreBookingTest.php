<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $moscowRoom;

    private Room $novosibirskRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->moscowRoom = Room::factory()->create();
        $this->novosibirskRoom = Room::factory()->for(Office::factory()->novosibirsk())->create();

        Sanctum::actingAs($this->user);
    }

    public function test_creates_booking_for_authenticated_user(): void
    {
        $otherUser = User::factory()->create();

        $this->postBooking([
            'user_id' => $otherUser->id,
            'title' => 'Планёрка',
        ])
            ->assertCreated()
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('room_id', $this->moscowRoom->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', ['user_id' => $this->user->id]);
    }

    public function test_stores_time_in_utc_regardless_of_client_offset(): void
    {
        $this->postBooking([
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2026-12-01T10:00:00+07:00',
            'ends_at' => '2026-12-01T11:00:00+07:00',
        ])
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-12-01T03:00:00.000000Z');

        $this->assertDatabaseHas('bookings', [
            'starts_at' => '2026-12-01 03:00:00',
            'ends_at' => '2026-12-01 04:00:00',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/bookings', $this->validPayload())->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_rejects_invalid_payload(array $overrides, string $invalidField): void
    {
        $this->postBooking($overrides)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidField);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'нет комнаты' => [['room_id' => null], 'room_id'],
            'несуществующая комната' => [['room_id' => 999_999], 'room_id'],
            'нет названия' => [['title' => ''], 'title'],
            'слишком длинное название' => [['title' => str_repeat('а', 256)], 'title'],
            'нет начала' => [['starts_at' => null], 'starts_at'],
            'не дата' => [['starts_at' => 'завтра в десять'], 'starts_at'],
            'без смещения' => [['starts_at' => '2026-12-01T10:00:00'], 'starts_at'],
            'попытка SQL-инъекции' => [['starts_at' => "2026-12-01T10:00:00+03:00' OR '1'='1"], 'starts_at'],
            'нет окончания' => [['ends_at' => null], 'ends_at'],
            'окончание раньше начала' => [['ends_at' => '2026-12-01T09:30:00+03:00'], 'ends_at'],
            'окончание равно началу' => [['ends_at' => '2026-12-01T10:00:00+03:00'], 'ends_at'],
        ];
    }

    public function test_accepts_utc_designator_and_time_without_seconds(): void
    {
        $this->postBooking([
            'starts_at' => '2026-12-01T07:00Z',
            'ends_at' => '2026-12-01T08:00:00Z',
        ])->assertCreated();
    }

    public function test_rejects_overlapping_booking(): void
    {
        $this->createBooking($this->moscowRoom, '2026-12-01T10:00:00+03:00', '2026-12-01T11:00:00+03:00');

        $this->postBooking([
            'starts_at' => '2026-12-01T10:30:00+03:00',
            'ends_at' => '2026-12-01T11:30:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'Комната уже занята в это время.']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function overlappingIntervalProvider(): array
    {
        return [
            'совпадает' => ['10:00', '11:00'],
            'внутри существующей' => ['10:15', '10:45'],
            'охватывает существующую' => ['09:45', '11:15'],
            'заходит на начало' => ['09:30', '10:15'],
            'заходит на конец' => ['10:45', '11:30'],
        ];
    }

    #[DataProvider('overlappingIntervalProvider')]
    public function test_rejects_any_kind_of_overlap(string $startTime, string $endTime): void
    {
        $this->createBooking($this->moscowRoom, '2026-12-01T10:00:00+03:00', '2026-12-01T11:00:00+03:00');

        $this->postBooking([
            'starts_at' => "2026-12-01T{$startTime}:00+03:00",
            'ends_at' => "2026-12-01T{$endTime}:00+03:00",
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_detects_overlap_when_offsets_differ(): void
    {
        $this->createBooking($this->moscowRoom, '2026-12-01T10:00:00+03:00', '2026-12-01T11:00:00+03:00');

        $this->postBooking([
            'starts_at' => '2026-12-01T14:00:00+07:00',
            'ends_at' => '2026-12-01T15:00:00+07:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_allows_back_to_back_bookings(): void
    {
        $this->createBooking($this->moscowRoom, '2026-12-01T10:00:00+03:00', '2026-12-01T11:00:00+03:00');

        $this->postBooking([
            'starts_at' => '2026-12-01T11:00:00+03:00',
            'ends_at' => '2026-12-01T12:00:00+03:00',
        ])->assertCreated();

        $this->postBooking([
            'starts_at' => '2026-12-01T09:00:00+03:00',
            'ends_at' => '2026-12-01T10:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_allows_same_time_in_another_room(): void
    {
        $this->createBooking($this->moscowRoom, '2026-12-01T10:00:00+03:00', '2026-12-01T11:00:00+03:00');
        $anotherMoscowRoom = Room::factory()->for($this->moscowRoom->office)->create();

        $this->postBooking(['room_id' => $anotherMoscowRoom->id])->assertCreated();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allowedDurationProvider(): array
    {
        return [
            'минимум 15 минут' => ['10:00', '10:15'],
            'максимум 4 часа' => ['10:00', '14:00'],
        ];
    }

    #[DataProvider('allowedDurationProvider')]
    public function test_allows_duration_within_limits(string $startTime, string $endTime): void
    {
        $this->postBooking([
            'starts_at' => "2026-12-01T{$startTime}:00+03:00",
            'ends_at' => "2026-12-01T{$endTime}:00+03:00",
        ])->assertCreated();
    }

    public function test_rejects_duration_longer_than_four_hours(): void
    {
        $this->postBooking([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T14:15:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Длительность брони — от 15 минут до 4 часов.']);
    }

    public function test_rejects_duration_shorter_than_fifteen_minutes(): void
    {
        $this->postBooking([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T10:10:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Длительность брони — от 15 минут до 4 часов.']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function offStepTimeProvider(): array
    {
        return [
            'начало не на шаге' => ['10:05:00', '11:00:00', 'starts_at'],
            'окончание не на шаге' => ['10:00:00', '11:10:00', 'ends_at'],
            'секунды в начале' => ['10:00:30', '11:00:00', 'starts_at'],
        ];
    }

    #[DataProvider('offStepTimeProvider')]
    public function test_rejects_time_off_fifteen_minute_step(string $startTime, string $endTime, string $invalidField): void
    {
        $this->postBooking([
            'starts_at' => "2026-12-01T{$startTime}+03:00",
            'ends_at' => "2026-12-01T{$endTime}+03:00",
        ])->assertUnprocessable()->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workingHoursBoundaryProvider(): array
    {
        return [
            'начало ровно в 09:00' => ['09:00', '10:00'],
            'окончание ровно в 20:00' => ['19:00', '20:00'],
        ];
    }

    #[DataProvider('workingHoursBoundaryProvider')]
    public function test_allows_booking_on_working_hours_boundaries(string $startTime, string $endTime): void
    {
        $this->postBooking([
            'starts_at' => "2026-12-01T{$startTime}:00+03:00",
            'ends_at' => "2026-12-01T{$endTime}:00+03:00",
        ])->assertCreated();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outsideWorkingHoursProvider(): array
    {
        return [
            'начинается до 09:00' => ['2026-12-01T08:45:00+03:00', '2026-12-01T09:30:00+03:00'],
            'заканчивается после 20:00' => ['2026-12-01T19:30:00+03:00', '2026-12-01T20:15:00+03:00'],
            'целиком ночью' => ['2026-12-01T23:00:00+03:00', '2026-12-02T00:00:00+03:00'],
        ];
    }

    #[DataProvider('outsideWorkingHoursProvider')]
    public function test_rejects_booking_outside_working_hours(string $startsAt, string $endsAt): void
    {
        $this->postBooking(['starts_at' => $startsAt, 'ends_at' => $endsAt])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_checks_working_hours_in_room_office_timezone(): void
    {
        // 18:00 по Москве — это 22:00 в Новосибирске.
        $this->postBooking([
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2026-12-01T18:00:00+03:00',
            'ends_at' => '2026-12-01T19:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        // 06:00 по Москве — это 10:00 в Новосибирске.
        $this->postBooking([
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2026-12-01T06:00:00+03:00',
            'ends_at' => '2026-12-01T07:00:00+03:00',
        ])->assertCreated();
    }

    public function test_checks_working_hours_for_utc_input(): void
    {
        // 06:00Z = 09:00 по Москве, 05:45Z = 08:45.
        $this->postBooking([
            'starts_at' => '2026-12-01T06:00:00Z',
            'ends_at' => '2026-12-01T07:00:00Z',
        ])->assertCreated();

        $this->postBooking([
            'starts_at' => '2026-12-01T05:45:00Z',
            'ends_at' => '2026-12-01T06:45:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_allows_any_date_including_past_and_weekend(): void
    {
        $this->postBooking([
            'starts_at' => '2020-01-04T10:00:00+03:00',
            'ends_at' => '2020-01-04T11:00:00+03:00',
        ])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBooking(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/bookings', array_merge($this->validPayload(), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'room_id' => $this->moscowRoom->id,
            'title' => 'Встреча',
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ];
    }

    private function createBooking(Room $room, string $startsAt, string $endsAt): Booking
    {
        return Booking::factory()->for($room)->create([
            'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc(),
        ]);
    }
}
