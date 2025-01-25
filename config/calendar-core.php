<?php

// config for Comhon/Calendar
return [

    /*
     | eloquent model to use for participants,
     | it must be instance of HasScheduleInterface
     */
    'participant_model' => \App\Models\User::class,

    /*
     | eloquent model to use for event creators
     */
    'creator_model' => \App\Models\User::class,

    /*
     | all configs related to events API
     | Warning! if you update any api config, don't forget to reset routes cache.
     */
    'api' => [
        /*
         | allow you to use built API routes to manage calendar events.
         | if false, all others api configs are not taken in account.
         */
        'active' => false,

        /*
         | the domain of your api
         */
        'domain' => null,

        /*
         | prefix to apply on all event routes
         */
        'prefix' => null,

        /*
         | middlewares to apply on all event routes
         */
        'middleware' => ['web', 'auth'],
    ],

    /*
     | if you want to define user access using policies, set this config to true.
     | don't forget to publish policies in your project in order to use policies.
     */
    'use_policies' => false,
];
