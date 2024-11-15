<?php

namespace Tests\Feature\Api;

use Carbon\Carbon;
use Comhon\Calendar\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Testing\Assert as PHPUnit;
use Tests\Models\User;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function testListEventsSuccess()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Event::factory(2)->hasAttached($user1, [], 'participants')->create();

        Event::factory()->hasAttached($user2, [], 'participants')->create();
        Event::factory([
            'start_at' => Carbon::now()->addYear(),
            'end_at' => Carbon::now()->addYear()->addHour(),
        ])->hasAttached($user2, [], 'participants')->create();

        Event::factory()->hasAttached(User::factory()->create(), [], 'participants')->create();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [$user1->id, $user2->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $data = $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'creator_id',
                        'start_at',
                        'end_at',
                        'schedulable_id',
                        'schedulable_type',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                        'pivot' => [
                            'participant_id',
                            'accepted',
                            'accept_choice_at',
                        ],
                    ],
                ],
            ])->json('data');

        $this->assertCount(2, collect($data)->filter(fn ($item) => $item['pivot']['participant_id'] == $user1->id));
        $this->assertCount(1, collect($data)->filter(fn ($item) => $item['pivot']['participant_id'] == $user2->id));
    }

    public function testListEventsUnprocessable()
    {
        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [[12, 13]],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'each element must be a string or a number',
            ]);
    }

    public function testListEventsForbidden()
    {
        $params = http_build_query([
            'participant_ids' => [User::factory()->create()->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);

        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertForbidden();
    }

    public function testListEventsUnauthorized()
    {
        $this->getJson('api/events')->assertUnauthorized();
    }

    /**
     * Warning! a specific config is set for this test in TestCase::getEnvironmentSetUp
     */
    public function testListEventsDontUseRoute()
    {
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson('api/events')
            ->assertNotFound();
    }

    public function testGetEventSuccess()
    {
        $user = User::factory()->create();
        $event = Event::factory()->hasAttached($user, [], 'participants')->create();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $this->actingAs($consumer)->getJson("api/events/{$event->id}")
            ->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'creator_id' => $event->creator_id,
                    'creator' => [
                        'id' => $event->creator_id,
                    ],
                    'start_at' => $event->start_at->toIsoString(),
                    'end_at' => $event->end_at->toIsoString(),
                    'schedulable_id' => $event->schedulable_id,
                    'schedulable_type' => $event->schedulable_type,
                    'created_at' => $event->created_at->toIsoString(),
                    'updated_at' => $event->updated_at->toIsoString(),
                    'deleted_at' => $event->deleted_at,
                ],
            ]);
    }

    public function testGetEventForbidden()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events/{$event->id}")
            ->assertForbidden();
    }

    public function testStoreEventSuccess()
    {
        $startAt = Carbon::now()->addMinute()->setMicro(0)->toIsoString();
        $endAt = Carbon::now()->addHour()->setMicro(0)->toIsoString();
        $inputs = [
            'name' => 'event name',
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
        $data = [
            ...$inputs,
        ];

        $consumer = User::factory()->hasConsumerAbility()->create();

        $response = $this->actingAs($consumer)->postJson('api/events', $inputs)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id']]) // just verify if id is present
            ->assertJson([
                'data' => $data,
            ]);

        $event = Event::find($response->json('data.id'));
        $this->assertNotNull($event);
        PHPUnit::assertArraySubset($data, $event->toArray());
    }

    public function testStoreEventForbidden()
    {
        $data = [];
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson('api/events', $data)
            ->assertForbidden();
    }

    public function testUpdateEventIsCreator()
    {
        $event = Event::factory()->create();

        $startAt = Carbon::now()->addMinute()->setMicro(0)->toIsoString();
        $endAt = Carbon::now()->addHour()->setMicro(0)->toIsoString();
        $inputs = [
            'name' => 'event name updated',
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
        $data = [
            ...$inputs,
            'start_at' => $event->start_at->toIsoString(), // MUST stay unchanged
            'end_at' => $event->end_at->toIsoString(), // MUST stay unchanged
        ];

        $this->actingAs($event->creator)->putJson("api/events/{$event->id}", $inputs)
            ->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $event->id,
                    ...$data,

                ],
            ]);

        PHPUnit::assertArraySubset($data, $event->refresh()->toArray());
    }

    public function testUpdateEventForbiddenAbilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->putJson("api/events/{$event->id}", $data)
            ->assertForbidden();
    }

    public function testUpdateEventForbiddenDateExceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->putJson("api/events/{$event->id}", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function testCancelEventCreator()
    {
        $event = Event::factory()->create();

        $response = $this->actingAs($event->creator)->postJson("api/events/{$event->id}/cancel");
        $response->assertNoContent();
        $this->assertEquals(0, Event::count());
        $this->assertEquals(1, Event::withTrashed()->count());
    }

    public function testCancelEventForbiddenAbility()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden();
        $this->assertEquals(1, Event::count());
    }

    public function testCancelEventForbiddenDateExceed()
    {
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);

        $this->assertEquals(1, Event::count());
    }

    public function testAcceptEventTrue()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => true]);
        $response->assertNoContent();
        $this->assertTrue($user->events()->first()->pivot->accepted);
    }

    public function testAcceptEventFalse()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => false]);
        $response->assertNoContent();
        $this->assertFalse($user->events()->first()->pivot->accepted);
    }

    public function testAcceptEventForbiddenNotParticipant()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden();
        $this->assertEquals(1, Event::count());
    }

    public function testAcceptEventForbiddenDateExceed()
    {
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => true]);
        $response->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function testGetEventParticipantsSuccess()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $event = Event::factory()
            ->hasAttached($user1, ['accepted' => true], 'participants')
            ->hasAttached($user2, ['accepted' => true], 'participants')
            ->create();

        $consumer = User::factory()->hasConsumerAbility()->create();
        $this->actingAs($consumer)->getJson("api/events/{$event->id}/participants")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'pivot' => [
                            'participant_id',
                            'event_id',
                            'accepted',
                            'accept_choice_at',
                        ],
                    ],
                ],
            ])->assertJson([
                'data' => [
                    [
                        'pivot' => [
                            'accepted' => true,
                        ],
                    ],
                ],
            ]);
    }

    public function testGetEventParticipantsForbidden()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events/{$event->id}/participants")
            ->assertForbidden();
    }

    public function testRescheduleEventIsCreator()
    {
        $event = Event::factory()->create();

        $startAt = Carbon::now()->addMinute()->setMicro(0)->toIsoString();
        $endAt = Carbon::now()->addHour()->setMicro(0)->toIsoString();
        $inputs = [
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
        $data = [
            ...$inputs,
        ];
        LaravelEvent::fake();
        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/reschedule", $inputs)
            ->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $event->id,
                    ...$data,

                ],
            ]);

        PHPUnit::assertArraySubset($data, $event->refresh()->toArray());
    }

    public function testRescheduleEventForbiddenAbilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/reschedule", $data)
            ->assertForbidden();
    }

    public function testRescheduleEventForbiddenDateExceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/reschedule", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function testSyncParticipantEventIsCreator()
    {
        $event = Event::factory()->create();
        $existing = User::factory()->hasAttached($event, [], 'events')->create();
        $new1 = User::factory()->create();
        $new2 = User::factory()->create();

        // MUST stay participant
        User::factory()->hasAttached($event, [], 'events')->create();

        $inputs = [
            'participant_ids' => [
                $new1->id,
                $new2->id,
                $existing->id,
            ],
        ];
        LaravelEvent::fake();
        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/sync", $inputs)
            ->assertNoContent();

        $this->assertEquals(4, $event->participants()->count());
        $this->assertNull($new1->events()->first()->pivot->accepted);
        $this->assertNull($new2->events()->first()->pivot->accepted);
    }

    public function testSyncParticipantEventWithAcceptedStatus()
    {
        $event = Event::factory()->create();
        $existing = User::factory()->hasAttached($event, [], 'events')->create();
        $new1 = User::factory()->create();
        $new2 = User::factory()->create();

        // MUST stay participant
        User::factory()->hasAttached($event, [], 'events')->create();

        $inputs = [
            'participant_ids' => [
                $new1->id,
                $new2->id,
                $existing->id,
            ],
            'accepted' => true,
        ];
        LaravelEvent::fake();
        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/sync", $inputs)
            ->assertNoContent();

        $this->assertEquals(4, $event->participants()->count());
        $this->assertTrue($new1->events()->first()->pivot->accepted);
        $this->assertTrue($new2->events()->first()->pivot->accepted);
    }

    public function testSyncParticipantEventForbiddenAbilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/participants/sync", $data)
            ->assertForbidden();
    }

    public function testSyncParticipantEventForbiddenDateExceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/sync", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function testDetachParticipantEventIsCreator()
    {
        $event = Event::factory()->create();
        $existing1 = User::factory()->hasAttached($event, [], 'events')->create();
        $existing2 = User::factory()->hasAttached($event, [], 'events')->create();
        $existing3 = User::factory()->hasAttached($event, [], 'events')->create();

        $inputs = [
            'participant_ids' => [
                $existing1->id,
                $existing2->id,
                User::factory()->create()->id,
            ],
        ];
        LaravelEvent::fake();
        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/detach", $inputs)
            ->assertNoContent();

        $this->assertEquals(1, $event->participants()->count());
        $this->assertEquals(0, $existing1->events()->count());
        $this->assertEquals(0, $existing2->events()->count());
        $this->assertEquals(1, $existing3->events()->count());
    }

    public function testDetachParticipantEventForbiddenAbilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/participants/detach", $data)
            ->assertForbidden();
    }

    public function testDetachParticipantEventForbiddenDateExceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/detach", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }
}
