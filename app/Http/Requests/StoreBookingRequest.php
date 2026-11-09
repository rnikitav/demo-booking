<?php

namespace App\Http\Requests;

use App\Enums\BookingDuration;
use App\Enums\IsoDateTimeFormat;
use App\Enums\WorkingHours;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => [
                'required',
                'integer',
                'exists:rooms,id',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'starts_at' => [
                'required',
                'string',
                IsoDateTimeFormat::validationRule(),
            ],
            'ends_at' => [
                'required',
                'string',
                IsoDateTimeFormat::validationRule(),
                'after:starts_at',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $dateTimeFormat = 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.';

        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Комната не найдена.',
            'room_id.exists' => 'Комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи — не длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.string' => $dateTimeFormat,
            'starts_at.date_format' => $dateTimeFormat,
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.string' => $dateTimeFormat,
            'ends_at.date_format' => $dateTimeFormat,
            'ends_at.after' => 'Окончание должно быть позже начала.',
        ];
    }

    /**
     * Правила бронирования проверяются только после того, как поля прошли базовую валидацию.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateBookingRules($validator);
            },
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('starts_at')->toString())->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_at')->toString())->utc();
    }

    private function validateBookingRules(Validator $validator): void
    {
        $room = Room::with('office')->findOrFail($this->integer('room_id'));
        $timezone = $room->office->timezone;

        $localStart = $this->startsAt()->setTimezone($timezone);
        $localEnd = $this->endsAt()->setTimezone($timezone);

        if (! $this->isOnStep($localStart)) {
            $validator->errors()->add('starts_at', 'Начало должно быть кратно 15 минутам: 10:00, 10:15, 10:30…');
        }

        if (! $this->isOnStep($localEnd)) {
            $validator->errors()->add('ends_at', 'Окончание должно быть кратно 15 минутам: 10:00, 10:15, 10:30…');
        }

        $durationMinutes = $localStart->diffInMinutes($localEnd);

        if ($durationMinutes < BookingDuration::Min->minutes() || $durationMinutes > BookingDuration::Max->minutes()) {
            $validator->errors()->add('ends_at', 'Длительность брони — от 15 минут до 4 часов.');
        }

        $workdayStart = $localStart->setTime(WorkingHours::Start->value, 0);
        $workdayEnd = $localStart->setTime(WorkingHours::End->value, 0);

        if ($localStart->lt($workdayStart) || $localEnd->gt($workdayEnd)) {
            $validator->errors()->add('starts_at', "Бронь должна целиком укладываться в рабочее время офиса 09:00–20:00 ({$timezone}).");
        }
    }

    private function isOnStep(CarbonImmutable $localTime): bool
    {
        return $localTime->minute % BookingDuration::Step->minutes() === 0 && $localTime->second === 0;
    }
}
