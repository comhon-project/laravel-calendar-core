<?php

namespace Tests\Feature\Services;

use App\Models\BadSchedulable;
use App\Models\TrainingProgramSimple;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Comhon\Calendar\Events\ParticipantsAttached;
use Comhon\Calendar\Events\ParticipantsDetached;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\SchedulableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as LaravelEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulableTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('providerBoolean')]
    public function test_set_participation_status_event_success($accepted)
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users = User::factory(2)->hasAttached($schedulable->event, [], 'events')->create();

        /** @var TrainingSession $schedulable */
        $otherSchedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $users[0]->events()->attach($otherSchedulable->event->id);

        app(SchedulableService::class)->setParticipationStatus($schedulable, $users[0], $accepted);

        $users[0]->refresh();
        $this->assertEquals($accepted, $users[0]->events->firstWhere('id', $schedulable->event->id)->pivot->accepted);
        $this->assertNotNull($users[0]->events->firstWhere('id', $schedulable->event->id)->pivot->accept_choice_at);

        // others must stay unchanged
        $users[0]->refresh();
        $this->assertNull($users[0]->events->firstWhere('id', $otherSchedulable->event->id)->pivot->accepted);
        $this->assertNull($users[0]->events->firstWhere('id', $otherSchedulable->event->id)->pivot->accept_choice_at);

        $users[1]->refresh();
        $this->assertNull($users[1]->events->first()->pivot->accepted);
        $this->assertNull($users[1]->events->first()->pivot->accept_choice_at);
    }

    #[DataProvider('providerBoolean')]
    public function test_set_participation_status_event_not_before_date($future)
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

    public function test_cancel_events_success()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();

        app(SchedulableService::class)->cancelEvents($schedulable);

        $this->assertNull(Event::find($event->id));
        $this->assertNotNull(Event::withTrashed()->find($event->id));

        foreach (Event::withTrashed()->get() as $event) {
            $this->assertNull($event->cancellation_reason);
        }
    }

    public function test_cancel_events_with_reason_success()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $event = $schedulable->event()->first();

        $reason = 'blabla';
        app(SchedulableService::class)->cancelEvents($schedulable, $reason);

        $this->assertNull(Event::find($event->id));
        $this->assertNotNull(Event::withTrashed()->find($event->id));

        foreach (Event::withTrashed()->get() as $event) {
            $this->assertEquals($reason, $event->cancellation_reason);
        }
    }

    public function test_cancel_events_multi()
    {
        /** @var TrainingProgramSimple $schedulable */
        $schedulable = TrainingProgramSimple::factory()
            ->has(Event::factory(), 'events')
            ->has(Event::factory(), 'events')
            ->create();

        $schedulable->refresh();
        $eventOne = $schedulable->events->first();
        $eventTwo = $schedulable->events->last();

        app(SchedulableService::class)->cancelEvents($schedulable);

        $this->assertNull(Event::find($eventOne->id));
        $this->assertNotNull(Event::withTrashed()->find($eventOne->id));
        $this->assertNull(Event::find($eventTwo->id));
        $this->assertNotNull(Event::withTrashed()->find($eventTwo->id));
    }

    #[DataProvider('providerBoolean')]
    public function test_cancel_events_not_before_date($future)
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

        app(SchedulableService::class)->cancelEvents($schedulable, null, $from);

        $this->assertNotNull(Event::find($event->id));
    }

    public function test_cancel_events_before_current_date_success()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory([
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now()->subDay(),
        ]), 'event')->create();
        $event = $schedulable->event()->first();

        $from = Carbon::now()->subMonth();

        app(SchedulableService::class)->cancelEvents($schedulable, null, $from);

        $this->assertNull(Event::find($event->id));
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    public function test_cancel_events_from_observer()
    {
        /** @var TrainingProgramSimple $schedulable */
        $schedulable = TrainingProgramSimple::factory()
            ->has(Event::factory(), 'events')
            ->has(Event::factory(), 'events')
            ->create();

        $schedulable->refresh();
        $eventOne = $schedulable->events->first();
        $eventTwo = $schedulable->events->last();

        $schedulable->delete();

        $this->assertNull(Event::find($eventOne->id));
        $this->assertNotNull(Event::withTrashed()->find($eventOne->id));
        $this->assertNull(Event::find($eventTwo->id));
        $this->assertNotNull(Event::withTrashed()->find($eventTwo->id));
    }

    public function test_schedule_event_success()
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

    public function test_schedule_event_in_past_success()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->subDays(2)->setMicro(0);
        $endAt = Carbon::now()->subDay()->setMicro(0);
        $creator = User::factory()->create();

        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);

        $event = $schedulable->event()->first();
        $this->assertEquals($startAt, $event->start_at);
        $this->assertEquals($endAt, $event->end_at);
    }

    public function test_schedule_event_failure_exists()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->has(Event::factory(), 'event')->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('Schedulable already has scheduling');
        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);
    }

    public function test_schedule_event_failure_conflict()
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

    public function test_schedule_event_schedulable_not_model_failure()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addHour()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('$schedulable must be instance of eloquent Model');
        app(SchedulableService::class)->schedule(new BadSchedulable, $startAt, $endAt, $creator);
    }

    public function test_schedule_event_schedulable_invalid_dates()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addHour()->setMicro(0);
        $endAt = Carbon::now()->addMinute()->setMicro(0);
        $creator = User::factory()->create();

        $this->expectExceptionMessage('$endAt must be after $startAt');
        app(SchedulableService::class)->schedule($schedulable, $startAt, $endAt, $creator);
    }

    public function test_reschedule_event_success()
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

    public function test_reschedule_event_failure_no_scheduling()
    {
        /** @var TrainingSession $schedulable */
        $schedulable = TrainingSession::factory()->create();
        $startAt = Carbon::now()->addMonth()->addMinute()->setMicro(0);
        $endAt = Carbon::now()->addMonth()->addHour()->setMicro(0);

        $this->expectExceptionMessage('schedulable has no scheduling');
        app(SchedulableService::class)->reschedule($schedulable, $startAt, $endAt);
    }

    public function test_reschedule_event_failure_several_schedulings()
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

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_success($accepted)
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

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_with_already_attached_success($accepted)
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

    public function test_sync_praticipants_to_schedulable_with_no_attachement_success()
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

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_not_before_date($future)
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

    public function test_detach_praticipants_from_schedulable_success()
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

    public function test_detach_praticipants_from_schedulable_with_already_attached_success()
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

    public function test_detach_praticipants_from_schedulable_with_no_attachement_success()
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

    #[DataProvider('providerBoolean')]
    public function test_detach_praticipants_from_schedulable_not_before_date($future)
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
