<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;
use Uzbek\Sms\Enums\SmsEncoding;

final class SegmentInfo extends Data
{
    /**
     * @param  int  $length  septets for GSM-7 (extension chars count twice), UTF-16 code units for UCS-2
     * @param  int  $perSegment  capacity of each segment at this encoding and segment count
     */
    public function __construct(
        public SmsEncoding $encoding,
        public int $length,
        public int $segments,
        public int $perSegment,
    ) {}
}
