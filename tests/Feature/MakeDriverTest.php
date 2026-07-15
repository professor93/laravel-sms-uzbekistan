<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(app_path('Sms'));
});

it('generates a driver class stub', function () {
    $this->artisan('make:sms-driver', ['name' => 'Acme'])->assertExitCode(0);

    $path = app_path('Sms/AcmeDriver.php');

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('class AcmeDriver extends AbstractDriver')
        ->and($contents)->toContain('resolveAuthenticator')
        ->and($contents)->toContain('doSend')
        ->and($contents)->toContain('ChecksDeliveryStatus')
        ->and($contents)->toContain('HandlesWebhooks')
        ->and($contents)->toContain('ChecksBalance')
        ->and($contents)->toContain("'acme'");
});

it('does not double the Driver suffix', function () {
    $this->artisan('make:sms-driver', ['name' => 'FooDriver'])->assertExitCode(0);

    expect(File::exists(app_path('Sms/FooDriver.php')))->toBeTrue()
        ->and(File::exists(app_path('Sms/FooDriverDriver.php')))->toBeFalse();
});
