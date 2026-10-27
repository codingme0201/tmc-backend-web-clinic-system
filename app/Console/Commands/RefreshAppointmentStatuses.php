<?php

namespace App\Console\Commands;

use App\Services\AppointmentAutoStatus;
use Illuminate\Console\Command;

class RefreshAppointmentStatuses extends Command
{
    protected $signature = 'appointments:refresh-statuses';

    protected $description = 'Expire overdue pending appointments and mark unchecked approved appointments as no-shows';

    public function handle(AppointmentAutoStatus $statuses): int
    {
        $this->info('Updated '.$statuses->refresh().' overdue appointment(s).');

        return self::SUCCESS;
    }
}
