<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moscowOffice = Office::factory()->state([
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
            'working_hours_from' => '09:00:00',
            'working_hours_to' => '20:00:00',
        ])->create();

        $this->novosibirskOffice = Office::factory()->state([
            'name' => 'Новосибирск',
            'timezone' => 'Asia/Novosibirsk',
            'working_hours_from' => '09:00:00',
            'working_hours_to' => '20:00:00',
        ])->create();

        $this->moscowRoom = Room::factory()->for($this->moscowOffice)->create(['name' => 'Alpha']);
        $this->novosibirskRoom = Room::factory()->for($this->novosibirskOffice)->create(['name' => 'Beta']);

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        Sanctum::actingAs($this->user);
    }

    // =========================================================================
    // 1. Брони одной комнаты не пересекаются. Встречи «впритык» разрешены.
    // =========================================================================

    public function test_no_overlap_for_conflicting_bookings(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 30)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 30)->toISOString(),
            'title' => 'Overlapping Meeting',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');
    }

    public function test_no_overlap_for_exact_same_time(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'title' => 'Exact Conflict',
        ]);

        $response->assertStatus(409);
    }

    public function test_no_overlap_when_new_booking_contains_existing(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(9, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(12, 0)->toISOString(),
            'title' => 'Containing Booking',
        ]);

        $response->assertStatus(409);
    }

    public function test_no_overlap_when_existing_booking_contains_new(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(12, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'title' => 'Contained Booking',
        ]);

        $response->assertStatus(409);
    }

    public function test_tight_bookings_are_allowed(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(12, 0)->toISOString(),
            'title' => 'Tight Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_tight_bookings_in_reverse_order(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(11, 0),
            'ends_at' => now()->addDay()->setTime(12, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'title' => 'Tight Meeting Before',
        ]);

        $response->assertCreated();
    }

    public function test_different_rooms_no_conflict(): void
    {
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'title' => 'Different Room Meeting',
        ]);

        $response->assertCreated();
    }

    // =========================================================================
    // 2. Длительность от 15 минут до 4 часов, шаг 15 минут.
    // =========================================================================

    public function test_minimum_duration_of_15_minutes(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 10)->toISOString(),
            'title' => 'Too Short Meeting',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');
    }

    public function test_minimum_duration_of_15_minutes_is_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 15)->toISOString(),
            'title' => '15-Minute Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_maximum_duration_of_4_hours_is_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(9, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(13, 0)->toISOString(),
            'title' => '4-Hour Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_duration_exceeding_4_hours_is_rejected(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(9, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(13, 15)->toISOString(),
            'title' => 'Too Long Meeting',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');
    }

    public function test_duration_step_rounded_up_to_15_minutes(): void
    {
        // 10:00-10:08 → rounded to 10:00-10:15
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 8)->toISOString(),
            'title' => 'Short Meeting',
        ]);

        // 10:00-10:15 conflicts with existing booking at 10:00-10:15
        Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(10, 15),
        ]);

        $response->assertStatus(409);
    }

    public function test_duration_step_30_minutes_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 30)->toISOString(),
            'title' => '30-Minute Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_duration_step_45_minutes_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 45)->toISOString(),
            'title' => '45-Minute Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_duration_step_60_minutes_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toISOString(),
            'title' => '60-Minute Meeting',
        ]);

        $response->assertCreated();
    }

    // =========================================================================
    // 3. Бронь укладывается в рабочее время 09:00–20:00 по местному времени офиса.
    // =========================================================================

    public function test_booking_within_working_hours_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(9, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(10, 0)->toISOString(),
            'title' => 'Morning Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_booking_ending_at_end_of_working_hours_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(19, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(20, 0)->toISOString(),
            'title' => 'Evening Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_booking_before_working_hours_rejected(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(8, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(9, 0)->toISOString(),
            'title' => 'Too Early Meeting',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Нерабочее время');
    }

    public function test_booking_starting_at_859_rejected(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(8, 59)->toISOString(),
            'ends_at' => now()->addDay()->setTime(9, 59)->toISOString(),
            'title' => 'Almost Early Meeting',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Нерабочее время');
    }

    public function test_booking_starting_at_830_rejected(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(8, 30)->toISOString(),
            'ends_at' => now()->addDay()->setTime(9, 30)->toISOString(),
            'title' => 'Early Meeting',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Нерабочее время');
    }

    public function test_booking_starting_at_2000_rejected(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(20, 0)->toISOString(),
            'ends_at' => now()->addDay()->setTime(21, 0)->toISOString(),
            'title' => 'Too Late Meeting',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Нерабочее время');
    }

    public function test_booking_starting_at_759pm_accepted(): void
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => now()->addDay()->setTime(19, 59)->toISOString(),
            'ends_at' => now()->addDay()->setTime(20, 59)->toISOString(),
            'title' => 'Late Evening Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_iso8601_with_positive_offset_moscow(): void
    {
        // Client sends time with +03:00 offset (Moscow timezone)
        $date = now()->addDay()->setTimezone('Europe/Moscow')->setTime(10, 0);
        $endDate = $date->copy()->addHour();

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => $date->toISOString(),
            'ends_at' => $endDate->toISOString(),
            'title' => 'Moscow Offset Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_iso8601_with_different_offset_for_novosibirsk_room(): void
    {
        // Client sends time with +07:00 offset (Novosibirsk timezone)
        $date = now()->addDay()->setTimezone('Asia/Novosibirsk')->setTime(10, 0);
        $endDate = $date->copy()->addHour();

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => $date->toISOString(),
            'ends_at' => $endDate->toISOString(),
            'title' => 'Novosibirsk Offset Meeting',
        ]);

        $response->assertCreated();
    }

    public function test_iso8601_with_utc_offset_crosses_working_hours(): void
    {
        // Client sends UTC time that translates to 8:00 Moscow time (before working hours)
        $date = now()->addDay()->setTimezone('UTC')->setTime(6, 0); // 8:00 Moscow = 06:00 UTC (winter)
        $endDate = $date->copy()->addHour();

        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'starts_at' => $date->toISOString(),
            'ends_at' => $endDate->toISOString(),
            'title' => 'UTC Early Meeting',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Нерабочее время');
    }

    // =========================================================================
    // 4. Отменить бронь может только её автор.
    // =========================================================================

    public function test_author_can_delete_booking(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/bookings/{$booking->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_non_author_cannot_delete_booking(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
        ]);

        Sanctum::actingAs($this->otherUser);

        $response = $this->deleteJson("/api/bookings/{$booking->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_unauthenticated_user_cannot_delete_booking(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
        ]);

        Sanctum::actingAs(null);

        $response = $this->deleteJson("/api/bookings/{$booking->id}");

        $response->assertUnauthorized();
    }

    public function test_deleted_booking_not_visible_in_index(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
        ]);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertOk();

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
