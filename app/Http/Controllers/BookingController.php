<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $conflict = Booking::where('room_id', $request->room_id)
            ->where('starts_at', '<=', $request->ends_at)
            ->where('ends_at', '>=', $request->starts_at)
            ->exists();

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        $start = Carbon::parse($request->starts_at);
        if ($start->hour < 9 || $start->hour >= 20) {
            return response()->json(['error' => 'Нерабочее время'], 422);
        }

        $booking = Booking::create([
            'room_id' => $request->room_id,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'title' => $request->title,
            'user_id' => $request->user()->id,
        ]);

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

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
