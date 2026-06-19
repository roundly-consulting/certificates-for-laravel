<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;

it('reports when no certificates exist', function (): void {
    $this->artisan('certificates:list')
        ->expectsOutputToContain('No certificates found.')
        ->assertExitCode(0);
});

it('lists certificates filtered by status and driver', function (): void {
    Certificate::factory()->issued()->create(['domain' => 'a.example.com', 'driver' => 'kubernetes']);
    Certificate::factory()->failed()->create(['domain' => 'b.example.com', 'driver' => 'null']);

    $this->artisan('certificates:list', ['--status' => 'issued'])
        ->expectsOutputToContain('a.example.com')
        ->assertExitCode(0);

    $this->artisan('certificates:list', ['--driver' => 'null'])
        ->expectsOutputToContain('b.example.com')
        ->assertExitCode(0);
});

it('lists certificates expiring within a window', function (): void {
    Certificate::factory()->expiring(5)->create(['domain' => 'soon.example.com']);
    Certificate::factory()->issued()->create(['domain' => 'later.example.com']);

    $this->artisan('certificates:list', ['--expiring' => '7'])
        ->expectsOutputToContain('soon.example.com')
        ->assertExitCode(0);
});
