<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

/**
 * Marks a driver whose sendMany() returns trustworthy per-message success flags
 * (foreach-single-send, or a batch endpoint that reports per-message results),
 * making per-message bulk fallback safe.
 */
interface SupportsBulkFallback {}
