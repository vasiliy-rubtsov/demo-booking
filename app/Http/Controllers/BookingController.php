<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Common\ExtCarbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\StoreBookingRequest;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request)
    {
        // Определяем временную зону офиса и рабочие часы в этой временной зоне
        $room = Room::find($request->room_id);
        $officeTimezone = $room->office->timezone;
        $workHoursFrom = $room->office->working_hours_from;
        $workHoursTo = $room->office->working_hours_to;

        // Переносим момент начала и конца бронирования во временную зону офиса
        $startsAt = ExtCarbon::parseWithOtherTimezone($request->starts_at, $officeTimezone);
        $endsAt = ExtCarbon::parseWithOtherTimezone($request->ends_at, $officeTimezone);

        // Корректируем момент окончания бронирования с учетом шага:
        // в длительность бронирования должно укладываться целое число шагов с округлением в большую сторону
        $df = Booking::BOOKING_STEP_MINUTES * ceil($startsAt->diffInMinutes($endsAt) / Booking::BOOKING_STEP_MINUTES);
        $endsAt = $startsAt->clone()->addMinutes($df);

        // Определяем моменты начала и конца работы офиса
        $workTimeFrom = $startsAt->clone()->setTimeFromTimeString($workHoursFrom);
        $workTimeTo = $startsAt->clone()->setTimeFromTimeString($workHoursTo);

        // Проверяем, занята ли комната на указанное время
        $conflict = Booking::where('room_id', $room->id)
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->exists();

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        // Проверяем, попадает ли заявленный интервал бронирования в рабочие часы офиса
        if (!($startsAt >= $workTimeFrom && $startsAt < $workTimeTo)) {
            return response()->json(['error' => 'Нерабочее время'], 422);
        }

        $booking = Booking::create([
            'room_id' => $room->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'title' => $request->title,
            'user_id' => $request->user()->id,
        ]);

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return $booking;
    }

    public function destroy(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        // Отменить бронь может только тот пользователь, который ее создал
        if ($booking->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking->delete();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
