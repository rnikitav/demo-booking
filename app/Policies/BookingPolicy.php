<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * Отменить бронь может только её автор.
     */
    public function delete(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }
}
