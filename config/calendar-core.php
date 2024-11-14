<?php

// config for Comhon/Calendar
return [

    /*
     | eloquent model to use for participants
     */
    'participant_model' => \App\Models\User::class,

    /*
     | eloquent model to use for event creators
     */
    'creator_model' => \App\Models\User::class,

    /*
     | prefix to apply on event routes
     */
    'route_prefix' => '',

    /*
     | middlewares to apply on all event routes
     */
    'middleware' => ['web', 'auth'],

    /*
     | allow you to use built API routes to manage calendar events.
     */
    'use_routes' => false,

    /*
     | if you want to define user access using policies, set this config to true.
     | don't forget to publish policies in your project in order to use policies.
     */
    'use_policies' => false,
];
