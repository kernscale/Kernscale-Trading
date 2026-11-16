<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('trading:sync')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('trading:reconcile')->everyMinute()->onOneServer()->withoutOverlapping();
