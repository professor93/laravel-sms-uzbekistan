<?php

declare(strict_types=1);

use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Enums\SmsEncoding;
use Uzbek\Sms\Support\SegmentCalculator;

it('counts plain GSM text as one segment up to 160 chars', function () {
    $info = SegmentCalculator::for(str_repeat('a', 160));

    expect($info->encoding)->toBe(SmsEncoding::Gsm7)
        ->and($info->length)->toBe(160)
        ->and($info->segments)->toBe(1)
        ->and($info->perSegment)->toBe(160);
});

it('splits GSM text into 153-char segments past 160', function () {
    $info = SegmentCalculator::for(str_repeat('a', 161));

    expect($info->segments)->toBe(2)
        ->and($info->perSegment)->toBe(153);

    expect(SegmentCalculator::for(str_repeat('a', 306))->segments)->toBe(2)
        ->and(SegmentCalculator::for(str_repeat('a', 307))->segments)->toBe(3);
});

it('counts GSM extension characters twice', function () {
    expect(SegmentCalculator::for(str_repeat('a', 158).'{')->segments)->toBe(1)
        ->and(SegmentCalculator::for(str_repeat('a', 158).'{')->length)->toBe(160)
        ->and(SegmentCalculator::for(str_repeat('a', 159).'{')->segments)->toBe(2);
});

it('detects Cyrillic as UCS-2 with 70-char segments', function () {
    $info = SegmentCalculator::for('Салом дунё');

    expect($info->encoding)->toBe(SmsEncoding::Ucs2)
        ->and($info->length)->toBe(10)
        ->and($info->segments)->toBe(1)
        ->and($info->perSegment)->toBe(70);

    expect(SegmentCalculator::for(str_repeat('б', 70))->segments)->toBe(1)
        ->and(SegmentCalculator::for(str_repeat('б', 71))->segments)->toBe(2)
        ->and(SegmentCalculator::for(str_repeat('б', 71))->perSegment)->toBe(67);
});

it('counts astral characters as two UTF-16 units', function () {
    $info = SegmentCalculator::for('😀');

    expect($info->encoding)->toBe(SmsEncoding::Ucs2)
        ->and($info->length)->toBe(2);
});

it('treats an empty text as zero segments', function () {
    $info = SegmentCalculator::for('');

    expect($info->length)->toBe(0)
        ->and($info->segments)->toBe(0);
});

it('exposes segment info on SentMessage', function () {
    $message = SentMessage::success('eskiz', '998901234567', 'Салом', 'id-1');

    expect($message->segments()->encoding)->toBe(SmsEncoding::Ucs2)
        ->and($message->segments()->segments)->toBe(1);
});
