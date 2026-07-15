<?php

declare(strict_types=1);

namespace Uzbek\Sms\Stats;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Uzbek\Sms\Data\StatsReport;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Models\SmsLog;

/**
 * Aggregates over sms_logs, so it needs database logging. Never throws — a
 * missing table comes back as an unavailable report.
 */
final class SmsStats
{
    public function report(
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $provider = null,
    ): StatsReport {
        $table = (new SmsLog)->getTable();

        try {
            if (! Schema::hasTable($table)) {
                return StatsReport::unavailable(sprintf(
                    'The [%s] table does not exist — enable database logging (SMS_LOG_DATABASE) and run the migrations.',
                    $table,
                ));
            }

            $query = $this->query($from, $to, $provider);

            $byStatus = $query->clone()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count): int => (int) $count)
                ->all();

            $total = (int) array_sum($byStatus);
            $delivered = $byStatus[DeliveryStatus::Delivered->value] ?? 0;

            $hasCost = Schema::hasColumn($table, 'cost');

            return new StatsReport(
                available: true,
                message: null,
                total: $total,
                byStatus: $byStatus,
                deliveryRate: $total > 0 ? round($delivered / $total, 4) : 0.0,
                totalCost: $hasCost ? (float) $query->clone()->sum('cost') : null,
                totalSegments: $hasCost ? (int) $query->clone()->sum('segments') : 0,
            );
        } catch (Throwable $e) {
            return StatsReport::unavailable($e->getMessage());
        }
    }

    /**
     * @return Builder<SmsLog>
     */
    private function query(?DateTimeInterface $from, ?DateTimeInterface $to, ?string $provider): Builder
    {
        return SmsLog::query()
            ->when($provider !== null, fn (Builder $query): Builder => $query->where('provider', $provider))
            ->when($from !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $to));
    }
}
