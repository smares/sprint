<?php

namespace App\Console\Commands;

use App\HealthCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sprint:health')]
#[Description('Prüft Datenbank, Speicher, Cache, Scheduler und Queue-Worker')]
class CheckHealth extends Command
{
    public function handle(HealthCheck $health): int
    {
        $checks = $health->run();

        foreach ($checks as $name => $check) {
            $label = match ($check['status']) {
                HealthCheck::OK => '<info>ok  </info>',
                HealthCheck::WARN => '<comment>warn</comment>',
                default => '<error>FAIL</error>',
            };

            $this->line(sprintf('%s  %-10s %s', $label, $name, $check['detail']));
        }

        $overall = $health->overall($checks);
        $this->newLine();
        $this->line("Gesamt: {$overall}");

        return $overall === 'down' ? self::FAILURE : self::SUCCESS;
    }
}
