<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('applyr:poll')->cron(config('applyr.polling.schedule'));
