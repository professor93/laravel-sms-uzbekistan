<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use LogicException;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;

final class PendingMessage
{
    private ?string $phone = null;

    private ?string $text = null;

    public function __construct(private readonly Driver $driver) {}

    public function to(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function send(): SentMessage
    {
        if ($this->phone === null || $this->phone === '') {
            throw new LogicException('No recipient set. Call to() before send().');
        }

        if ($this->text === null || $this->text === '') {
            throw new LogicException('No text set. Call text() before send().');
        }

        return $this->driver->send($this->phone, $this->text);
    }
}
