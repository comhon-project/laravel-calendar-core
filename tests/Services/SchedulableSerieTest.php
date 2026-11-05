<?php

namespace Tests\Feature\Services;

use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Comhon\Calendar\DTO\SchedulableSerie;
use Comhon\Calendar\Enums\EventSelection;
use Comhon\Calendar\Events\ParticipantsAttached;
use Comhon\Calendar\Events\ParticipantsDetached;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\SchedulableSerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as LaravelEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulableSerieTest extends TestCase
{
    use RefreshDatabase;

    public function getTrainingWithSessions(): TrainingProgram
    {
        return TrainingProgram::factory()
            ->has(TrainingSession::factory()->has(Event::factory(), 'event'), 'sessions')
            ->has(TrainingSession::factory()->has(Event::factory([
                'start_at' => Carbon::now()->addDays(1),
                'end_at' => Carbon::now()->addDays(2),
            ]), 'event'), 'sessions')->create();
    }

    #[DataProvider('providerBoolean')]
    public function test_set_participation_status_event_success($accepted)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        foreach ($training->sessions as $session) {
            $users[0]->events()->attach($session->event->id);
            $users[1]->events()->attach($session->event->id);
        }

        app(SchedulableSerieService::class)->setParticipationStatus($schedulableSerie, $users[0], $accepted);

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
            $this->assertCount(2, $participants);
            foreach ($participants as $participant) {
                if ($participant->id == $users[0]->id) {
                    if ($accepted) {
                        $this->assertTrue($participant->pivot->accepted);
                    } else {
                        $this->assertFalse($participant->pivot->accepted);
                    }
                    $this->assertNotNull($participant->pivot->accept_choice_at);
                } else {
                    $this->assertNull($participant->pivot->accepted);
                    $this->assertNull($participant->pivot->accept_choice_at);
                }
            }
        }
    }

    #[DataProvider('providerBoolean')]
    public function test_set_participation_status_event_not_before_date($future)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $user = User::factory()->create();

        foreach ($training->sessions as $session) {
            $user->events()->attach([$session->event->id]);
        }

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        app(SchedulableSerieService::class)->setParticipationStatus($schedulableSerie, $user, true, $from);

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
            $this->assertCount(1, $participants);
            foreach ($participants as $participant) {
                $this->assertNull($participant->pivot->accepted);
                $this->assertNull($participant->pivot->accept_choice_at);
            }
        }
    }

    public function test_cancel_events_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        app(SchedulableSerieService::class)->cancelEvents($schedulableSerie);

        $this->assertEquals(0, Event::count());
        $this->assertEquals(2, Event::withTrashed()->count());

        foreach (Event::withTrashed()->get() as $event) {
            $this->assertNull($event->cancellation_reason);
        }
    }

    public function test_cancel_events_with_reason_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        $reason = 'blabla';
        app(SchedulableSerieService::class)->cancelEvents($schedulableSerie, $reason);

        $this->assertEquals(0, Event::count());
        $this->assertEquals(2, Event::withTrashed()->count());

        foreach (Event::withTrashed()->get() as $event) {
            $this->assertEquals($reason, $event->cancellation_reason);
        }
    }

    #[DataProvider('providerBoolean')]
    public function test_cancel_events_not_before_date($future)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        app(SchedulableSerieService::class)->cancelEvents($schedulableSerie, null, $from);

        $this->assertEquals(2, Event::count());
    }

    public function test_cancel_events_before_current_date_success()
    {
        $training = TrainingProgram::factory()
            ->has(TrainingSession::factory()->has(Event::factory([
                'start_at' => Carbon::now()->subDays(2),
                'end_at' => Carbon::now()->subDay(),
            ]), 'event'), 'sessions')
            ->has(TrainingSession::factory()->has(Event::factory(), 'event'), 'sessions')->create();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        $from = Carbon::now()->subMonth();

        app(SchedulableSerieService::class)->cancelEvents($schedulableSerie, null, $from);

        $this->assertEquals(0, Event::count());
        $this->assertEquals(2, Event::withTrashed()->count());
    }

    public function test_cancel_events_from_observer()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        $training->delete();

        $this->assertEquals(0, Event::count());
        $this->assertEquals(2, Event::withTrashed()->count());
    }

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_serie_success($accepted)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $attached = app(SchedulableSerieService::class)->syncParticipants($schedulableSerie, $users->pluck('id'), $accepted);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals($users->pluck('id')->all(), $attached->all());

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
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
    }

    public function test_sync_praticipants_to_schedulable_serie_with_immutable_dates_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        $attached = app(SchedulableSerieService::class)->syncParticipants(
            $schedulableSerie,
            $users->pluck('id'),
            from: CarbonImmutable::now(),
            to: CarbonImmutable::now()->addMonth(),
        );

        $this->assertEquals($users->pluck('id')->all(), $attached->all());
        foreach ($training->refresh()->sessions as $session) {
            $this->assertCount(2, $session->event->participants);
        }
    }

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_serie_with_already_attached_success($accepted)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        foreach ($training->sessions as $session) {
            $users[0]->events()->attach([$session->event->id => ['accepted' => false]]);
        }

        LaravelEvent::fake();
        $attached = app(SchedulableSerieService::class)->syncParticipants($schedulableSerie, $users->pluck('id'), $accepted);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals([$users[1]->id], $attached->all());

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
            $this->assertCount(2, $participants);
            $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged
            if ($accepted) {
                $this->assertTrue($participants[1]->pivot->accepted);
            } else {
                $this->assertNull($participants[1]->pivot->accepted);
            }
        }
    }

    public function test_sync_praticipants_to_schedulable_serie_with_no_attachement_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $user = User::factory()->create();

        foreach ($training->sessions as $session) {
            $user->events()->attach([$session->event->id => ['accepted' => false]]);
        }

        LaravelEvent::fake();
        $attached = app(SchedulableSerieService::class)->syncParticipants($schedulableSerie, [$user->id]);
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEmpty($attached->all());

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
            $this->assertCount(1, $participants);
            $this->assertFalse($participants[0]->pivot->accepted); // must stay unchanged
        }
    }

    #[DataProvider('providerBoolean')]
    public function test_sync_praticipants_to_schedulable_serie_not_before_date($future)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        LaravelEvent::fake();
        $attached = app(SchedulableSerieService::class)->syncParticipants($schedulableSerie, $users->pluck('id'), true, $from);
        LaravelEvent::assertNotDispatched(ParticipantsAttached::class);

        $this->assertEquals([], $attached->all());

        $sessions = $training->refresh()->sessions;
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $participants = $session->event->participants;
            $this->assertCount(0, $participants);
        }
    }

    public function test_detach_praticipants_from_schedulable_serie_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        foreach ($training->sessions as $session) {
            $users[0]->events()->attach([$session->event->id => ['accepted' => false]]);
            $users[1]->events()->attach([$session->event->id => ['accepted' => false]]);
        }

        LaravelEvent::fake();
        $detached = app(SchedulableSerieService::class)->detachParticipants($schedulableSerie, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals($users->pluck('id')->all(), $detached->all());

        $sessions = $training->refresh()->sessions;
        foreach ($sessions as $session) {
            $this->assertCount(0, $session->event->participants);
        }
    }

    public function test_detach_praticipants_from_schedulable_serie_with_already_attached_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        foreach ($training->sessions as $session) {
            $users[0]->events()->attach([$session->event->id => ['accepted' => false]]);
        }

        LaravelEvent::fake();
        $detached = app(SchedulableSerieService::class)->detachParticipants($schedulableSerie, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals([$users[0]->id], $detached->all());

        $sessions = $training->refresh()->sessions;
        foreach ($sessions as $session) {
            $this->assertCount(0, $session->event->participants);
        }
    }

    public function test_detach_praticipants_from_schedulable_serie_with_no_attachement_success()
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        LaravelEvent::fake();
        $detached = app(SchedulableSerieService::class)->detachParticipants($schedulableSerie, $users->pluck('id'));
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEmpty($detached->all());

        $sessions = $training->refresh()->sessions;
        foreach ($sessions as $session) {
            $this->assertCount(0, $session->event->participants);
        }
    }

    #[DataProvider('providerBoolean')]
    public function test_detach_praticipants_from_schedulable_serie_not_before_date($future)
    {
        $training = $this->getTrainingWithSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        foreach ($training->sessions as $session) {
            $users[0]->events()->attach([$session->event->id => ['accepted' => false]]);
            $users[1]->events()->attach([$session->event->id => ['accepted' => false]]);
        }

        $from = null;
        if ($future) {
            Carbon::setTestNow(Carbon::now()->addMonth());
        } else {
            $from = Carbon::now()->addMonth();
        }

        LaravelEvent::fake();
        $detached = app(SchedulableSerieService::class)->detachParticipants($schedulableSerie, $users->pluck('id'), $from);
        LaravelEvent::assertNotDispatched(ParticipantsDetached::class);

        $this->assertEquals([], $detached->all());

        $sessions = $training->refresh()->sessions;
        foreach ($sessions as $session) {
            $this->assertCount(2, $session->event->participants);
        }
    }

    #[DataProvider('providerNotStartedSelection')]
    public function test_sync_participants_to_schedulable_serie_not_started_events_only(EventSelection $selection)
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        $attached = app(SchedulableSerieService::class)
            ->syncParticipants($schedulableSerie, $users->pluck('id'), selection: $selection);

        $this->assertCount(2, $attached);
        $this->assertCount(0, $ongoingEvent->participants);
        $this->assertCount(2, $futureEvent->participants);
    }

    #[DataProvider('providerNotStartedSelection')]
    public function test_detach_participants_from_schedulable_serie_not_started_events_only(EventSelection $selection)
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();
        $users[0]->events()->attach([$ongoingEvent->id, $futureEvent->id]);
        $users[1]->events()->attach([$ongoingEvent->id, $futureEvent->id]);

        $detached = app(SchedulableSerieService::class)
            ->detachParticipants($schedulableSerie, [$users[0]->id], selection: $selection);

        $this->assertEquals([$users[0]->id], $detached->all());
        $this->assertEqualsCanonicalizing($users->pluck('id')->all(), $ongoingEvent->participants->pluck('id')->all());
        $this->assertEquals([$users[1]->id], $futureEvent->participants->pluck('id')->all());
    }

    #[DataProvider('providerNotStartedSelection')]
    public function test_set_participation_status_schedulable_serie_not_started_events_only(EventSelection $selection)
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $user = User::factory()->create();
        $user->events()->attach([$ongoingEvent->id, $futureEvent->id]);

        app(SchedulableSerieService::class)
            ->setParticipationStatus($schedulableSerie, $user, true, selection: $selection);

        $this->assertNull($ongoingEvent->participants->first()->pivot->accepted);
        $this->assertTrue($futureEvent->participants->first()->pivot->accepted);
    }

    #[DataProvider('providerNotStartedSelection')]
    public function test_cancel_schedulable_serie_events_not_started_events_only(EventSelection $selection)
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');

        app(SchedulableSerieService::class)->cancelEvents($schedulableSerie, selection: $selection);

        $this->assertNotNull(Event::find($ongoingEvent->id));
        $this->assertNull(Event::find($futureEvent->id));
        $this->assertNotNull(Event::withTrashed()->find($futureEvent->id));
    }

    #[DataProvider('providerSelectionBound')]
    public function test_sync_participants_to_schedulable_serie_selection_bound(EventSelection $selection, bool $included)
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        app(SchedulableSerieService::class)
            ->syncParticipants($schedulableSerie, $users->pluck('id'), from: $futureEvent->start_at, selection: $selection);

        $this->assertCount(0, $ongoingEvent->participants);
        $this->assertCount($included ? 2 : 0, $futureEvent->participants);
    }

    public function test_sync_participants_to_schedulable_serie_not_started_events_with_to()
    {
        [$training, $ongoingEvent, $futureEvent] = $this->getTrainingWithOngoingAndFutureSessions();
        $schedulableSerie = new SchedulableSerie($training, 'sessions');
        $users = User::factory(2)->create();

        $attached = app(SchedulableSerieService::class)->syncParticipants(
            $schedulableSerie,
            $users->pluck('id'),
            to: $futureEvent->start_at,
            selection: EventSelection::StartingFrom,
        );

        $this->assertCount(0, $attached);
        $this->assertCount(0, $ongoingEvent->participants);
        $this->assertCount(0, $futureEvent->participants);
    }

    public static function providerNotStartedSelection()
    {
        return [
            [EventSelection::StartingFrom],
            [EventSelection::StartingAfter],
        ];
    }

    public static function providerSelectionBound()
    {
        return [
            [EventSelection::StartingFrom, true],
            [EventSelection::StartingAfter, false],
        ];
    }

    private function getTrainingWithOngoingAndFutureSessions(): array
    {
        $training = TrainingProgram::factory()
            ->has(TrainingSession::factory()->has(Event::factory([
                'start_at' => Carbon::now()->subHour(),
                'end_at' => Carbon::now()->addHour(),
            ]), 'event'), 'sessions')
            ->has(TrainingSession::factory()->has(Event::factory([
                'start_at' => Carbon::now()->addDay(),
                'end_at' => Carbon::now()->addDay()->addHour(),
            ]), 'event'), 'sessions')
            ->create();
        $events = Event::orderBy('start_at')->get();

        return [$training, $events[0], $events[1]];
    }
}
