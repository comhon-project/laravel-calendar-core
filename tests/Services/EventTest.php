<?php

namespace Tests\Feature\Services;

use Carbon\Carbon;
use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\DTO\SchedulableSerie;
use Comhon\Calendar\Events\EventRescheduled;
use Comhon\Calendar\Events\ParticipantsAttached;
use Comhon\Calendar\Events\ParticipantsDetached;
use Comhon\Calendar\Events\ParticipationStatusSet;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\EventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as LaravelEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\BadSchedulable;
use Tests\Models\TrainingProgram;
use Tests\Models\TrainingSession;
use Tests\Models\User;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public static function providerAccepted()
    {
        return [
            [false],
            [true],
        ];
    }

    public function testGetSchedulableEventsQuerySuccess()
    {
        /** @var SchedulableInterface $schedulable */
        $schedulable = TrainingSession::factory()->create();

        $query = app(EventService::class)->getSchedulableEventsQuery($schedulable);
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" = ? and "training_sessions"."deleted_at" is null))) and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $this->assertEquals(
            ['session', $schedulable->id],
            $query->getBindings(),
        );
    }

    public function testGetSchedulableEventsQueryWithDates()
    {
        /** @var SchedulableInterface $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $from = Carbon::now()->setMicro(0);
        $to = Carbon::now()->addHour()->setMicro(0);

        $query = app(EventService::class)->getSchedulableEventsQuery($schedulable, $from, $to);
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" = ? and "training_sessions"."deleted_at" is null))) and "end_at" > ? and "start_at" < ? and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $bindings = $query->getBindings();
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $to);
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $from);
    }

    public function testGetSchedulableEventsQueryFailure()
    {
        $this->expectExceptionMessage('$schedulable must be instance of eloquent Model');
        app(EventService::class)->getSchedulableEventsQuery(new BadSchedulable);
    }

    public function testGetSchedulableSerieEventsQuerySuccess()
    {
        /** @var TrainingProgram $training */
        $training = TrainingProgram::factory()->create();

        $query = app(EventService::class)->getSchedulableSerieEventsQuery(new SchedulableSerie($training, 'sessions'));
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" in (select "id" from "training_sessions" where "training_sessions"."training_program_id" = ? and "training_sessions"."training_program_id" is not null and "training_sessions"."deleted_at" is null) and "training_sessions"."deleted_at" is null))) and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $this->assertEquals(
            ['session', $training->id],
            $query->getBindings(),
        );
    }

    public function testGetSchedulableSerieEventsQueryWithDates()
    {
        /** @var TrainingProgram $training */
        $training = TrainingProgram::factory()->create();
        $from = Carbon::now()->setMicro(0);
        $to = Carbon::now()->addHour()->setMicro(0);

        $query = app(EventService::class)->getSchedulableSerieEventsQuery(new SchedulableSerie($training, 'sessions'), $from, $to);
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" in (select "id" from "training_sessions" where "training_sessions"."training_program_id" = ? and "training_sessions"."training_program_id" is not null and "training_sessions"."deleted_at" is null) and "training_sessions"."deleted_at" is null))) and "end_at" > ? and "start_at" < ? and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $bindings = $query->getBindings();
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $to);
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $from);
    }

    public function testGetParticipantEventsQuerySchedulableSuccess()
    {
        /** @var SchedulableInterface $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $user = User::factory()->create();

        $query = app(EventService::class)->getParticipantEventsQuery($user, $schedulable);
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" = ? and "training_sessions"."deleted_at" is null))) and exists (select * from "users" inner join "calendar_event_participants" on "users"."id" = "calendar_event_participants"."participant_id" where "calendar_events"."id" = "calendar_event_participants"."event_id" and "id" = ? and "users"."deleted_at" is null) and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $this->assertEquals(
            ['session', $schedulable->id, $user->id],
            $query->getBindings(),
        );
    }

    public function testGetParticipantEventsQuerySchedulableSerieSuccess()
    {
        /** @var TrainingProgram $training */
        $training = TrainingProgram::factory()->create();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $user = User::factory()->create();

        $query = app(EventService::class)->getParticipantEventsQuery($user, $schedulableSerie);
        $this->assertEquals(
            'session',
            $query->getBindings()[0],
        );
    }

    public function testGetParticipantEventsQueryWithDates()
    {
        /** @var SchedulableInterface $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $from = Carbon::now()->setMicro(0);
        $to = Carbon::now()->addHour()->setMicro(0);
        $syncFrom = Carbon::now()->addHour()->setMicro(0);
        $user = User::factory()->create();

        $query = app(EventService::class)->getParticipantEventsQuery($user, $schedulable, $from, $to, $syncFrom);
        $this->assertEquals(
            'select * from "calendar_events" where (("calendar_events"."schedulable_type" = ? and exists (select * from "training_sessions" where "calendar_events"."schedulable_id" = "training_sessions"."id" and "id" = ? and "training_sessions"."deleted_at" is null))) and "end_at" > ? and "start_at" < ? and exists (select * from "users" inner join "calendar_event_participants" on "users"."id" = "calendar_event_participants"."participant_id" where "calendar_events"."id" = "calendar_event_participants"."event_id" and "id" = ? and "calendar_event_participants"."created_at" >= ? and "users"."deleted_at" is null) and "calendar_events"."deleted_at" is null',
            $query->toSql(),
        );
        $bindings = $query->getBindings();
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $syncFrom);
        $userId = array_pop($bindings);
        $this->assertTrue($userId == $user->id);
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $to);
        $dateTime = array_pop($bindings);
        $this->assertTrue($dateTime == $from);
    }

    public function testRescheduleEventSuccess()
    {
        $event = Event::factory()->create();

        $newStart = Carbon::now()->addDay()->setMicro(0);
        $newEnd = Carbon::now()->addDay()->addHours(5)->setMicro(0);

        LaravelEvent::fake();
        app(EventService::class)->reschedule($event, $newStart, $newEnd);
        LaravelEvent::assertDispatched(EventRescheduled::class);

        $event->refresh();
        $this->assertEquals($newStart, $event->start_at);
        $this->assertEquals($newEnd, $event->end_at);
    }

    public function testRescheduleEventInvalidDates()
    {
        $event = Event::factory()->create();

        $newStart = Carbon::now()->addDay()->addHours(5)->setMicro(0);
        $newEnd = Carbon::now()->addDay()->setMicro(0);

        $this->expectExceptionMessage('$endAt must be after $startAt');
        app(EventService::class)->reschedule($event, $newStart, $newEnd);
    }

    public function testRescheduleEventExceed()
    {
        $event = Event::factory([
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->subDay()->addHour(),
        ])->create();

        $newStart = Carbon::now()->addDay()->setMicro(0);
        $newEnd = Carbon::now()->addDay()->addHours(5)->setMicro(0);

        $this->expectExceptionMessage('event is already finished');
        app(EventService::class)->reschedule($event, $newStart, $newEnd);
    }

    #[DataProvider('providerAccepted')]
    public function testSetParticipationStatusEventSuccess($accepted)
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $user->events()->attach($event);

        LaravelEvent::fake();
        app(EventService::class)->setParticipationStatus($event, $user, $accepted);
        LaravelEvent::assertDispatched(ParticipationStatusSet::class);

        $event->refresh();
        $this->assertEquals($accepted, $event->participants->first()->pivot->accepted);
        $this->assertNotNull($event->participants->first()->pivot->accept_choice_at);
    }

    public function testSetParticipationStatusEventFailure()
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();

        $this->expectExceptionMessage("doesn't belong to event");
        app(EventService::class)->setParticipationStatus($event, $user, true);
    }

    public function testCancelEventSuccess()
    {
        $event = Event::factory()->create();

        app(EventService::class)->cancel($event);

        $this->assertNull(Event::find($event->id));
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    #[DataProvider('providerAccepted')]
    public function testSyncPraticipantsToEventSuccess($accepted)
    {
        $event = Event::factory()->create();
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $attached = app(EventService::class)->syncParticipants($event, $users->pluck('id'), $accepted);
        LaravelEvent::assertDispatched(ParticipantsAttached::class);

        $this->assertEquals($users->pluck('id')->all(), $attached->all());

        $participants = $event->refresh()->participants;
        $this->assertCount(2, $participants);
        foreach ($participants as $participant) {
            if ($accepted) {
                $this->assertTrue($participant->pivot->accepted);
                $this->assertNotNull($participant->pivot->accept_choice_at);
            } else {
                $this->assertNull($participant->pivot->accepted);
                $this->assertNull($participant->pivot->accept_choice_at);
            }
        }
    }

    #[DataProvider('providerAccepted')]
    public function testSyncPraticipantsToEventWithAlreadyAttachedSuccess($accepted)
    {
        $event = Event::factory()->create();
        $users = User::factory(2)->create();
        $users[0]->events()->attach([$event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $attached = app(EventService::class)->syncParticipants($event, $users->pluck('id'), $accepted);
        LaravelEvent::assertDispatched(ParticipantsAttached::class);

        $this->assertEquals([$users[1]->id], $attached->all());

        $participants = $event->refresh()->participants()->orderBy('id')->get();
        $this->assertCount(2, $participants);
        $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged
        if ($accepted) {
            $this->assertTrue($participants[1]->pivot->accepted);
        } else {
            $this->assertNull($participants[1]->pivot->accepted);
        }
    }

    public function testSyncPraticipantsToEventWithNoAttachementSuccess()
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $user->events()->attach([$event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $attached = app(EventService::class)->syncParticipants($event, [$user->id]);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEmpty($attached->all());

        $participants = $event->refresh()->participants()->orderBy('id')->get();
        $this->assertCount(1, $participants);
        $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged

    }

    public function testDetachPraticipantsFromEventSuccess()
    {
        $event = Event::factory()->create();
        $users = User::factory(2)
            ->hasAttached($event, [], 'events')
            ->create();

        LaravelEvent::fake();
        $detached = app(EventService::class)->detachParticipants($event, $users->pluck('id'));
        LaravelEvent::assertDispatched(ParticipantsDetached::class);

        $this->assertEquals($users->pluck('id')->all(), $detached->all());
        $this->assertCount(0, $event->refresh()->participants);
    }

    public function testDetachPraticipantsFromEventWithAlreadyAttachedSuccess()
    {
        $event = Event::factory()->create();
        $users = User::factory(2)->create();
        $users[0]->events()->attach([$event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $detached = app(EventService::class)->detachParticipants($event, $users->pluck('id'));
        LaravelEvent::assertDispatched(ParticipantsDetached::class);

        $this->assertEquals([$users[0]->id], $detached->all());
        $this->assertCount(0, $event->refresh()->participants);
    }

    public function testDetachPraticipantsFromEventWithNoAttachementSuccess()
    {
        $event = Event::factory()->create();
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $detached = app(EventService::class)->detachParticipants($event, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEmpty($detached->all());
        $this->assertCount(0, $event->refresh()->participants);
    }
}
