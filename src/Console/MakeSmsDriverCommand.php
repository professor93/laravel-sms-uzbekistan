<?php

declare(strict_types=1);

namespace Uzbek\Sms\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

final class MakeSmsDriverCommand extends GeneratorCommand
{
    protected $name = 'make:sms-driver';

    protected $description = 'Create a new SMS driver class with the capability contracts explained';

    protected $type = 'SMS driver';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/driver.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Sms';
    }

    protected function getNameInput(): string
    {
        return Str::finish(trim((string) $this->argument('name')), 'Driver');
    }

    protected function buildClass($name): string
    {
        $alias = Str::snake(Str::beforeLast(class_basename($name), 'Driver'));

        return str_replace(
            ['{{ alias }}', '{{ env }}'],
            [$alias !== '' ? $alias : 'custom', strtoupper($alias !== '' ? $alias : 'custom')],
            parent::buildClass($name),
        );
    }
}
