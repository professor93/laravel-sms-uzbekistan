<?php

declare(strict_types=1);

namespace Uzbek\Sms\Support;

use Uzbek\Sms\Data\SegmentInfo;
use Uzbek\Sms\Enums\SmsEncoding;

/**
 * GSM 03.38 / UCS-2 segment math: one Cyrillic character silently switches a
 * message to UCS-2 and halves the per-segment capacity from 160 to 70.
 */
final class SegmentCalculator
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑܧ¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENSION = "€^{}[]~|\\\f";

    private const GSM_SINGLE = 160;

    private const GSM_MULTI = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_MULTI = 67;

    public static function for(string $text): SegmentInfo
    {
        $septets = self::gsmSeptets($text);

        if ($septets !== null) {
            return self::info(SmsEncoding::Gsm7, $septets, self::GSM_SINGLE, self::GSM_MULTI);
        }

        $units = (int) (strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2);

        return self::info(SmsEncoding::Ucs2, $units, self::UCS2_SINGLE, self::UCS2_MULTI);
    }

    /**
     * Septet count, or null when any character falls outside GSM 03.38.
     */
    private static function gsmSeptets(string $text): ?int
    {
        $septets = 0;

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $septets += 1;
            } elseif (mb_strpos(self::GSM_EXTENSION, $char) !== false) {
                $septets += 2;
            } else {
                return null;
            }
        }

        return $septets;
    }

    private static function info(SmsEncoding $encoding, int $length, int $single, int $multi): SegmentInfo
    {
        $segments = match (true) {
            $length === 0 => 0,
            $length <= $single => 1,
            default => (int) ceil($length / $multi),
        };

        return new SegmentInfo(
            encoding: $encoding,
            length: $length,
            segments: $segments,
            perSegment: $segments > 1 ? $multi : $single,
        );
    }
}
