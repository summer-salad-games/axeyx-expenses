<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('attachments:reconcile --delete')->hourly()->withoutOverlapping();
