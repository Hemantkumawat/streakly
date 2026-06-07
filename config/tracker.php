<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily points goal
    |--------------------------------------------------------------------------
    | Drives the daily progress bar and the top tier of the heatmap colour.
    */
    'daily_goal' => (int) env('TRACKER_DAILY_GOAL', 30),

    /*
    |--------------------------------------------------------------------------
    | Allow public registration
    |--------------------------------------------------------------------------
    | Set TRACKER_ALLOW_REGISTRATION=false in your production .env once you've
    | created your account, so strangers can't sign up on your public URL.
    */
    'allow_registration' => filter_var(env('TRACKER_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOL),

];
