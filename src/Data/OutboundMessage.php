<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;

final class OutboundMessage extends Data
{
    public function __construct(
        public string $phone,
        public string $text,
    ) {}

    /**
     * @param  array<int, string>  $phones
     * @return array<int, self>
     */
    public static function sameText(array $phones, string $text): array
    {
        return array_map(fn (string $phone): self => new self($phone, $text), $phones);
    }
}
