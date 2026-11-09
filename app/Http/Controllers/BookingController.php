<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * Автор брони — владелец токена; время сохраняется в UTC.
     *
     * Проверка пересечения и вставка идут в одной транзакции под блокировкой
     * строки комнаты (SELECT ... FOR UPDATE): параллельный запрос на ту же
     * комнату ждёт коммита первого, затем видит его бронь и получает 422.
     */
    public function store(StoreBookingRequest $request): Booking
    {
        $booking = DB::transaction(function () use ($request): Booking {
            $room = Room::query()->lockForUpdate()->findOrFail($request->integer('room_id'));

            if (Booking::query()->overlapping($room, $request->startsAt(), $request->endsAt())->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Комната уже занята в это время.']);
            }

            return $request->user()->bookings()->create([
                'room_id' => $room->id,
                'title' => $request->string('title')->toString(),
                'starts_at' => $request->startsAt(),
                'ends_at' => $request->endsAt(),
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return $booking;
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
