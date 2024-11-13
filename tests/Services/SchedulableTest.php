<?php

namespace Tests\Feature\Services;

use Carbon\Carbon;
use Comhon\Calendar\Events\ParticipantsAttached;
use Comhon\Calendar\Events\ParticipantsDetached;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\SchedulableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as LaravelEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\BadSchedulable;
use Tests\Models\TrainingSession;
use Tests\Models\User;
use Tests\TestCase;

class SchedulableTest extends TestCase
{
    use RefreshDatabase;

    public static function providerAccepted()
    {
        return [
            [false],
            [true],
        ];
    }

    public static function providerFuture()
    {
        return [
            [false],
            [true],
        ];
    }

    #[DataProvider('providerAccepted')]
    public function testSetParticipationStatusEventSuccess($accepted)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->hasAttached($schedulable->event, [], 'events')->create();

        /** @var TrainingSession $schedulable */
        $otherBookable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users[0]->events()->attach($otherBookable->event->id);

        app(SchedulableService::class)->setParticipationStatus($schedulable, $users[0], $accepted);

        $users[0]->refresh();
        $this->assertEquals($accepted, $users[0]->events->firstWhere('id', $schedulable->event->id)->pivot->accepted);
        $this->assertNotNull($users[0]->events->firstWhere('id', $schedulable->event->id)->pivot->accept_choice_at);

        // others must stay unchanged
        $users[0]->refresh();
        $this->assertNull($users[0]->events->firstWhere('id', $otherBookable->event->id)->pivot->accepted);
        $this->assertNull($users[0]->events->firstWhere('id', $otherBookable->event->id)->pivot->accept_choice_at);

        $users[1]->refresh();
        $this->assertNull($users[1]->events->first()->pivot->accepted);
        $this->assertNull($users[1]->events->first()->pivot->accept_choice_at);
    }

    #[DataProvider('providerFuture')]
    public function testSetParticipationStatusEventNotBeforeDate($future)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $user = User::factory()->hasAttached($schedulable->event, [], 'events')->create();

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        app(SchedulableService::class)->setParticipationStatus($schedulable, $user, true, $from);

        $user->refresh();
        $this->assertNull($user->events->firstWhere('id', $schedulable->event->id)->pivot->accepted);
        $this->assertNull($user->events->firstWhere('id', $schedulable->event->id)->pivot->accept_choice_at);
    }

    public function testCancelEventsSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();

        app(SchedulableService::class)->cancelEvents($schedulable);

        $this->assertNull(Event::find($event->id));
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    #[DataProvider('providerFuture')]
    public function testCancelEventsNotBeforeDate($future)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        app(SchedulableService::class)->cancelEvents($schedulable, $from);

        $this->assertNotNull(Event::find($event->id));
    }

    public function testCancelEventsBeforeCurrentFailure()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();

        $from = Carbon::now()->subMonth();

        $this->expectExceptionMessage('date must be a future date');
        app(SchedulableService::class)->cancelEvents($schedulable, $from);
    }

    public function testScheduleEventSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);

        $event = $schedulable->event()->first();
        $this->assertEquals($startAt, $event->start_at);
        $this->assertEquals($endAt, $event->end_at);
        $this->assertTrue($creator->is($event->creator));
        $this->assertTrue($schedulable->is($event->schedulable));
    }

    public function testScheduleEventFailureExists()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('Schedulable already has scheduling');
        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);
    }

    public function testScheduleEventFailureConflict()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('already scheduling');
        $aquired = Cache::lock("schedule_event_{$schedulable->getKey()}_{$schedulable->getTable()}", 1)->get(
            fn () => app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator)
        );
    }

    public function testScheduleEventSchedulableNotModelFailure()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('$schedulable must be instance of eloquent Model');
        app(SchedulableService::class)->schedule(new BadSchedulable, $startAt, $endAt, $creator);
    }

    public function testScheduleEventSchedulableInvalidDates()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addHour()->setMicro(0);
        $endAt = Carbon::now()->addMinute()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('$endAt must be after $startAt');
        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);
    }

    public function testRescheduleEventSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();
        $startAt = Carbon::now()->addMonth()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addMonth()->addHour()->setMicro(0);

        app(SchedulableService::class)->reschedule($schedulable, $startAt, $endAt);

        $event = $event->refresh();
        $this->assertEquals($startAt, $event->start_at);
        $this->assertEquals($endAt, $event->end_at);
    }

    public function testRescheduleEventFailureNoScheduling()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMonth()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addMonth()->addHour()->setMicro(0);

        $this->expectExceptionMessage('schedulable has no scheduling');
        app(SchedulableService::class)->reschedule($schedulable, $startAt, $endAt);
    }

    public function testRescheduleEventFailureSeveralSchedulings()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()
            ->has(Event::factory(), 'event')
            ->has(Event::factory(), 'event')
            ->create();
        $startAt = Carbon::now()->addMonth()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addMonth()->addHour()->setMicro(0);

        $this->expectExceptionMessage('cannot reschedule schedulable with several schedulings');
        app(SchedulableService::class)->reschedule($schedulable, $startAt, $endAt);
    }

    #[DataProvider('providerAccepted')]
    public function testSyncPraticipantsToBookableSuccess($accepted)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $attached = app(SchedulableService::class)->syncParticipants($schedulable, $users->pluck('id'), $accepted);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals($users->pluck('id')->all(), $attached->all());

        $participants = $schedulable->refresh()->event->participants;
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
    public function testSyncPraticipantsToBookableWithAlreadyAttachedSuccess($accepted)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->create();
        $users[0]->events()->attach([$schedulable->event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $attached = app(SchedulableService::class)->syncParticipants($schedulable, $users->pluck('id'), $accepted);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals([$users[1]->id], $attached->all());

        $participants = $schedulable->refresh()->event->participants()->orderBy('id')->get();
        $this->assertCount(2, $participants);
        $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged
        if ($accepted) {
            $this->assertTrue($participants[1]->pivot->accepted);
        } else {
            $this->assertNull($participants[1]->pivot->accepted);
        }
    }

    public function testSyncPraticipantsToBookableWithNoAttachementSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $user = User::factory()->create();
        $user->events()->attach([$schedulable->event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $attached = app(SchedulableService::class)->syncParticipants($schedulable, [$user->id]);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEmpty($attached->all());

        $participants = $schedulable->refresh()->event->participants()->orderBy('id')->get();
        $this->assertCount(1, $participants);
        $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged
    }

    #[DataProvider('providerFuture')]
    public function testSyncPraticipantsToBookableNotBeforeDate($future)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->create();

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        LaravelEvent::fake();
        $attached = app(SchedulableService::class)->syncParticipants($schedulable, $users->pluck('id'), true, $from);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals([], $attached->all());

        $participants = $schedulable->refresh()->event->participants;
        $this->assertCount(0, $participants);
    }

    public function testDetachPraticipantsFromBookableSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)
            ->hasAttached($schedulable->event, [], 'events')
            ->create();

        LaravelEvent::fake();
        $detached = app(SchedulableService::class)->detachParticipants($schedulable, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals($users->pluck('id')->all(), $detached->all());
        $this->assertCount(0, $schedulable->refresh()->event->participants);
    }

    public function testDetachPraticipantsFromBookableWithAlreadyAttachedSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->create();
        $users[0]->events()->attach([$schedulable->event->id => ['accepted' => false]]);

        LaravelEvent::fake();
        $detached = app(SchedulableService::class)->detachParticipants($schedulable, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals([$users[0]->id], $detached->all());
        $this->assertCount(0, $schedulable->refresh()->event->participants);
    }

    public function testDetachPraticipantsFromBookableWithNoAttachementSuccess()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $detached = app(SchedulableService::class)->detachParticipants($schedulable, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEmpty($detached->all());
        $this->assertCount(0, $schedulable->refresh()->event->participants);
    }

    #[DataProvider('providerFuture')]
    public function testDetachPraticipantsFromBookableNotBeforeDate($future)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)
            ->hasAttached($schedulable->event, [], 'events')
            ->create();

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        LaravelEvent::fake();
        $detached = app(SchedulableService::class)->detachParticipants($schedulable, $users->pluck('id'), $from);
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals([], $detached->all());
        $this->assertCount(2, $schedulable->refresh()->event->participants);
    }
}
