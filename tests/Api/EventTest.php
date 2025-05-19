<?php

namespace Tests\Feature\Api;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ParticipantScoperAll;
use App\Services\ParticipantScoperAuth;
use Carbon\Carbon;
use Comhon\Calendar\Contracts\ParticipantScoperInterface;
use Comhon\Calendar\Http\Controllers\EventController;
use Comhon\Calendar\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Testing\Assert as PHPUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private function registerShedulableExporters(array $exporters)
    {
        app()->bind('morphed-model-exporters', function () use ($exporters) {
            return new class($exporters)
            {
                public function __construct(private array $exporters) {}

                public function __invoke()
                {
                    return $this->exporters;
                }
            };
        });
    }

    public function test_list_events_success()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // events that must be returned
        Event::factory(2)->hasAttached($user1, [], 'participants')->create();

        // events that must NOT be returned
        Event::factory()->hasAttached($user1, [], 'participants')->create()->delete();
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
            ])->assertJsonMissingPath('data.0.schedulable')
            ->json('data');

        $this->assertCount(2, collect($data)->filter(fn ($item) => $item['pivot']['participant_id'] == $user1->id));
        $this->assertCount(1, collect($data)->filter(fn ($item) => $item['pivot']['participant_id'] == $user2->id));
    }

    #[DataProvider('providerBoolean')]
    public function test_list_events_with_canceled($embedCanceled)
    {
        $user = User::factory()->create();

        Event::factory()->hasAttached($user, [], 'participants')->create();
        Event::factory()->hasAttached($user, [], 'participants')->create()->delete();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [$user->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'embed_canceled' => $embedCanceled,
        ]);
        $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertOk()
            ->assertJsonCount($embedCanceled ? 2 : 1, 'data');
    }

    public function test_list_events_with_schedulable_without_exporter_success()
    {
        /** @var TrainingSession $trainingSession */
        $trainingSession = TrainingSession::factory()->has(Event::factory(), 'event')->create();

        /** @var Appointment $appointment */
        $appointment = Appointment::factory()->has(Event::factory(), 'event')->create();

        $user = User::factory()
            ->hasAttached($trainingSession->event, [], 'events')
            ->hasAttached($appointment->event, [], 'events')->create();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [$user->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $data = $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'schedulable_id',
                        'schedulable_type',
                    ],
                ],
            ])->assertJsonMissingPath('data.0.schedulable');
    }

    #[DataProvider('providerBoolean')]
    public function test_list_events_with_schedulable_with_exporter_success($embedMorphedModels)
    {
        $this->registerShedulableExporters([
            TrainingSession::class => [
                'query_builder' => fn ($query) => $query->with('program:id,name')->select('id', 'training_program_id'),
                'model_exporter' => fn ($model) => $model,
            ],
            Appointment::class => [
                'model_exporter' => AppointmentResource::class,
            ],
        ]);

        /** @var TrainingSession $trainingSession */
        $trainingSession = TrainingSession::factory()->has(Event::factory(), 'event')->create();

        /** @var Appointment $appointment */
        $appointment = Appointment::factory()->has(Event::factory(), 'event')->create();

        $user = User::factory()
            ->hasAttached($trainingSession->event, [], 'events')
            ->hasAttached($appointment->event, [], 'events')
            ->create();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [$user->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'embed_schedulable' => $embedMorphedModels,
        ]);
        $response = $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'schedulable_id',
                        'schedulable_type',
                    ],
                ],
            ]);

        if ($embedMorphedModels) {
            $response->assertJsonStructure(['data' => ['*' => ['schedulable']]]);
            $data = collect($response->json('data'))->sortBy('id')->map(function ($item) {
                return [
                    'schedulable_id' => $item['schedulable_id'],
                    'schedulable_type' => $item['schedulable_type'],
                    'schedulable' => $item['schedulable'],
                ];
            });

            $this->assertEquals(
                [
                    [
                        'schedulable_id' => $trainingSession->id,
                        'schedulable_type' => 'session',
                        'schedulable' => [
                            'id' => $trainingSession->id,
                            'training_program_id' => $trainingSession->program->id,
                            'program' => [
                                'id' => $trainingSession->program->id,
                                'name' => $trainingSession->program->name,
                            ],
                        ],
                    ],
                    [
                        'schedulable_id' => $appointment->id,
                        'schedulable_type' => 'appointment',
                        'schedulable' => [
                            'id' => $appointment->id,
                            'created_at' => $appointment->created_at->toIsoString(),
                        ],
                    ],
                ],
                $data->toArray()
            );
        } else {
            $response->assertJsonMissingPath('data.0.schedulable');
        }
    }

    public function test_list_events_with_schedulable_with_unused_exporter_success()
    {
        $this->registerShedulableExporters([
            TrainingSession::class => [
                'query_builder' => fn ($query) => $query->with('program:id,name')->select('id', 'training_program_id'),
                'model_exporter' => fn ($model) => $model,
            ],
        ]);

        /** @var Appointment $appointment */
        $appointment = Appointment::factory()->has(Event::factory(), 'event')->create();

        $user = User::factory()
            ->hasAttached(Event::factory(), [], 'events')
            ->hasAttached($appointment->event, [], 'events')
            ->create();

        $consumer = User::factory()->hasConsumerAbility()->create();

        $params = http_build_query([
            'participant_ids' => [$user->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'embed_schedulable' => true,
        ]);
        $data = $this->actingAs($consumer)->getJson("api/events?{$params}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'schedulable_id',
                        'schedulable_type',
                    ],
                ],
            ])->assertJsonMissingPath('data.0.schedulable')
            ->assertJsonMissingPath('data.1.schedulable');
    }

    public function test_list_events_unprocessable()
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

    public function test_list_events_forbidden()
    {
        $params = http_build_query([
            'participant_ids' => [User::factory()->create()->id],
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);

        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events?$params")
            ->assertForbidden();
    }

    public function test_list_events_unauthorized()
    {
        $this->getJson('api/events')->assertUnauthorized();
    }

    /**
     * Warning! a specific config is set for this test in TestCase::getEnvironmentSetUp
     */
    public function test_list_events_dont_use_route()
    {
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson('api/events')
            ->assertNotFound();
    }

    public function test_list_auth_user_events_success()
    {
        $consumer = User::factory()->hasConsumerAbility()->create();

        // events that must be returned
        Event::factory()->hasAttached($consumer, [], 'participants')->create();

        // events that must NOT be returned
        Event::factory()->hasAttached($consumer, [], 'participants')->create()->delete();
        Event::factory([
            'start_at' => Carbon::now()->addYear(),
            'end_at' => Carbon::now()->addYear()->addHour(),
        ])->hasAttached($consumer, [], 'participants')->create();
        Event::factory()->for($consumer, 'creator')->create();
        Event::factory()->hasAttached(User::factory()->create(), [], 'participants')->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $this->actingAs($consumer)->getJson("api/user/events?{$params}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
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
            ])->assertJsonPath('data.0.pivot.participant_id', $consumer->id);
    }

    #[DataProvider('providerBoolean')]
    public function test_list_auth_events_with_canceled($embedCanceled)
    {
        /** @var User $consumer */
        $consumer = User::factory()->create();

        Event::factory()->hasAttached($consumer, [], 'participants')->create();
        Event::factory()->hasAttached($consumer, [], 'participants')->create()->delete();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'embed_canceled' => $embedCanceled,
        ]);
        $this->actingAs($consumer)->getJson("api/user/events?{$params}")
            ->assertOk()
            ->assertJsonCount($embedCanceled ? 2 : 1, 'data');
    }

    #[DataProvider('providerBoolean')]
    public function test_list_auth_user_events_with_schedulable_with_exporter_success($embedMorphedModels)
    {
        $this->registerShedulableExporters([
            TrainingSession::class => [
                'query_builder' => fn ($query) => $query->with('program:id,name')->select('id', 'training_program_id'),
                'model_exporter' => fn ($model) => $model,
            ],
        ]);

        /** @var TrainingSession $trainingSession */
        $trainingSession = TrainingSession::factory()->has(Event::factory(), 'event')->create();

        $consumer = User::factory()
            ->hasAttached($trainingSession->event, [], 'events')
            ->hasConsumerAbility()
            ->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'embed_schedulable' => $embedMorphedModels,
        ]);
        $response = $this->actingAs($consumer)->getJson("api/user/events?{$params}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'schedulable_id',
                        'schedulable_type',
                    ],
                ],
            ]);

        if ($embedMorphedModels) {
            $response->assertJsonStructure(['data' => ['*' => ['schedulable']]])
                ->assertJson([
                    'data' => [
                        '0' => [
                            'schedulable_id' => $trainingSession->id,
                            'schedulable_type' => 'session',
                            'schedulable' => [
                                'id' => $trainingSession->id,
                                'training_program_id' => $trainingSession->program->id,
                                'program' => [
                                    'id' => $trainingSession->program->id,
                                    'name' => $trainingSession->program->name,
                                ],
                            ],
                        ],
                    ],
                ]);
        } else {
            $response->assertJsonMissingPath('data.0.schedulable');
        }
    }

    public function test_list_auth_user_events_with_as_creator_success()
    {
        $consumer = User::factory()->hasConsumerAbility()->create();

        Event::factory()->hasAttached($consumer, [], 'participants')->create();
        Event::factory([
            'start_at' => Carbon::now()->addYear(),
            'end_at' => Carbon::now()->addYear()->addHour(),
        ])->hasAttached($consumer, [], 'participants')->create();
        Event::factory()->for($consumer, 'creator')->create();

        Event::factory()->hasAttached(User::factory()->create(), [], 'participants')->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'include_as_creator' => true,
        ]);
        $data = $this->actingAs($consumer)->getJson("api/user/events?{$params}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
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
                    ],
                ],
            ])->assertJsonPath('data.0.pivot.participant_id', $consumer->id)
            ->assertJsonMissingPath('data.1.pivot');
    }

    #[DataProvider('providerBoolean')]
    public function test_list_auth_user_events_with_type_filter_success($hasNullValue)
    {
        $consumer = User::factory()->hasConsumerAbility()->create();

        Event::factory(['schedulable_type' => 'foo'])->hasAttached($consumer, [], 'participants')->create();
        Event::factory(['schedulable_type' => 'bar'])->hasAttached($consumer, [], 'participants')->create();
        Event::factory(['schedulable_type' => null])->hasAttached($consumer, [], 'participants')->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
            'types' => [
                ...($hasNullValue ? [''] : []),
                'foo',
            ],
        ]);

        $expectedCount = $hasNullValue ? 2 : 1;
        $data = $this->actingAs($consumer)->getJson("api/user/events?{$params}")
            ->assertOk()
            ->assertJsonCount($expectedCount, 'data')
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
                    ],
                ],
            ]);
    }

    public function test_list_user_events_success()
    {
        /** @var User $consumer */
        $consumer = User::factory()->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $this->actingAs($consumer)->getJson("api/users/{$consumer->id}/events?{$params}")
            ->assertOk();
    }

    public function test_list_user_events_forbidden()
    {
        /** @var User $consumer */
        $consumer = User::factory()->create();

        $user = User::factory()->create();

        $params = http_build_query([
            'from' => Carbon::now()->subDay()->toIsoString(),
            'to' => Carbon::now()->addDay()->toIsoString(),
        ]);
        $this->actingAs($consumer)->getJson("api/users/{$user->id}/events?{$params}")
            ->assertForbidden();
    }

    public function test_get_event_success()
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

    public function test_get_event_forbidden()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events/{$event->id}")
            ->assertForbidden();
    }

    public function test_store_event_success()
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

    public function test_store_event_with_participants_success()
    {
        $consumer = User::factory()->hasConsumerAbility()->create();
        $startAt = Carbon::now()->addMinute()->setMicro(0)->toIsoString();
        $endAt = Carbon::now()->addHour()->setMicro(0)->toIsoString();
        $inputs = [
            'name' => 'event name',
            'start_at' => $startAt,
            'end_at' => $endAt,
            'participants' => [
                'participant_ids' => [$consumer->id],
                'accepted' => true,
            ],
        ];
        $data = [
            ...$inputs,
        ];
        unset($data['participants']);

        $response = $this->actingAs($consumer)->postJson('api/events', $inputs)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id']]) // just verify if id is present
            ->assertJson([
                'data' => $data,
            ]);

        $event = Event::find($response->json('data.id'));
        $this->assertNotNull($event);
        PHPUnit::assertArraySubset($data, $event->toArray());

        $this->assertEquals(1, $event->participants()->count());
        $this->assertEquals($consumer->id, $event->participants()->first()->id);
        $this->assertTrue($event->participants()->first()->pivot->accepted);
    }

    public function test_store_event_forbidden()
    {
        $data = [];
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson('api/events', $data)
            ->assertForbidden();
    }

    public function test_update_event_is_creator()
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

    public function test_update_event_forbidden_abilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->putJson("api/events/{$event->id}", $data)
            ->assertForbidden();
    }

    public function test_update_event_forbidden_date_exceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->putJson("api/events/{$event->id}", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function test_cancel_event_creator()
    {
        $event = Event::factory()->create();

        $response = $this->actingAs($event->creator)->postJson("api/events/{$event->id}/cancel");
        $response->assertNoContent();
        $this->assertEquals(0, Event::count());
        $this->assertEquals(1, Event::withTrashed()->count());
    }

    public function test_cancel_event_with_reason()
    {
        $event = Event::factory()->create();
        $cancellationReason = 'blablabla';

        $response = $this->actingAs($event->creator)->postJson("api/events/{$event->id}/cancel", [
            'cancellation_reason' => $cancellationReason,
        ]);
        $response->assertNoContent();
        $this->assertEquals(0, Event::count());
        $this->assertEquals(1, Event::withTrashed()->count());
        $this->assertEquals($cancellationReason, Event::withTrashed()->first()->cancellation_reason);
    }

    public function test_cancel_event_forbidden_ability()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden();
        $this->assertEquals(1, Event::count());
    }

    public function test_cancel_event_forbidden_date_exceed()
    {
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);

        $this->assertEquals(1, Event::count());
    }

    public function test_accept_event_true()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => true]);
        $response->assertNoContent();
        $this->assertTrue($user->events()->first()->pivot->accepted);
    }

    public function test_accept_event_false()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => false]);
        $response->assertNoContent();
        $this->assertFalse($user->events()->first()->pivot->accepted);
    }

    public function test_accept_event_forbidden_not_participant()
    {
        $event = Event::factory()->create();
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("api/events/{$event->id}/cancel")
            ->assertForbidden();
        $this->assertEquals(1, Event::count());
    }

    public function test_accept_event_forbidden_date_exceed()
    {
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();
        /** @var User $user */
        $user = User::factory()->hasAttached($event, [], 'events')->create();

        $response = $this->actingAs($user)->postJson("api/events/{$event->id}/accept", ['accept' => true]);
        $response->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function test_get_event_participants_success()
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

    public function test_get_event_participants_forbidden()
    {
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->getJson("api/events/{$event->id}/participants")
            ->assertForbidden();
    }

    public function test_reschedule_event_is_creator()
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

    public function test_reschedule_event_forbidden_abilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/reschedule", $data)
            ->assertForbidden();
    }

    public function test_reschedule_event_forbidden_date_exceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/reschedule", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function test_sync_participant_event_is_creator()
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

    public function test_sync_participant_event_with_accepted_status()
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

    #[DataProvider('providerBoolean')]
    public function test_sync_participant_event_with_participant_scope($all)
    {
        $scoper = $all ? ParticipantScoperAll::class : ParticipantScoperAuth::class;
        $this->app->bind(ParticipantScoperInterface::class, $scoper);
        $event = Event::factory()->create();
        $notAccepted = User::factory()->create();

        $consumer = $event->creator;
        $inputs = [
            'participant_ids' => [
                $consumer->id,
                $notAccepted->id,
            ],
        ];
        LaravelEvent::fake();
        $request = $this->actingAs($consumer)->postJson("api/events/{$event->id}/participants/sync", $inputs);

        if ($all) {
            $request->assertNoContent();
        } else {
            $request->assertUnprocessable()
                ->assertJson(['message' => 'The selected participant ids is invalid.']);
        }
    }

    public function test_sync_participant_event_forbidden_abilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/participants/sync", $data)
            ->assertForbidden();
    }

    public function test_sync_participant_event_forbidden_date_exceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/sync", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function test_detach_participant_event_is_creator()
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

    public function test_detach_participant_event_forbidden_abilities()
    {
        $data = [];
        $event = Event::factory()->create();
        /** @var User $consumer */
        $consumer = User::factory()->create();
        $this->actingAs($consumer)->postJson("api/events/{$event->id}/participants/detach", $data)
            ->assertForbidden();
    }

    public function test_detach_participant_event_forbidden_date_exceed()
    {
        $data = [];
        $event = Event::factory(['end_at' => Carbon::now()->subDay()])->create();

        $this->actingAs($event->creator)->postJson("api/events/{$event->id}/participants/detach", $data)
            ->assertForbidden()
            ->assertJson(['message' => 'event is already finished']);
    }

    public function test_verify_user_has_schedule_interface()
    {
        $this->expectExceptionMessage('the use model must be instanceof HasScheduleInterface');
        (new EventController)->verifyUserHasScheduleInterface(Event::factory()->create());
    }

    public function test_verify_same_table()
    {
        config(['calendar-core.participant_model' => Event::class]);

        $this->expectExceptionMessage("the config 'calendar-core.participant_model' doesn't match with auth user model (must have same database table)");
        (new EventController)->verifySameTable('calendar-core.participant_model', User::factory()->create());
    }

    public function test_verify_is_has_schedule_interface()
    {
        config(['calendar-core.creator_model' => Event::class]);

        $this->expectExceptionMessage("the config 'calendar-core.creator_model' must be instanceof HasScheduleInterface");
        (new EventController)->verifyIsHasScheduleInterface('calendar-core.creator_model');
    }
}
