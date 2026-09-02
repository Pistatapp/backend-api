<?php

namespace App\Services;

use App\Models\Irrigation;
use App\Models\User;
use Carbon\Carbon;

/**
 * Single source of truth for the irrigation edit/confirmation lifecycle.
 *
 * The timestamps are deliberately calculated in the farm business timezone;
 * persistence is only used for explicit confirmations. Legacy admin-approved
 * records remain final through is_verified_by_admin.
 */
class IrrigationLifecycleService
{
    public const TIMEZONE = 'Asia/Tehran';
    public const OPERATOR_HOURS = 48;
    public const ADMIN_HOURS = 72;

    public function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public function isAdminConfirmed(Irrigation $irrigation): bool
    {
        return (bool) $irrigation->is_verified_by_admin || $irrigation->admin_confirmed_at !== null;
    }

    public function operatorWindowEnd(Irrigation $irrigation): ?Carbon
    {
        return $irrigation->end_time?->copy()->setTimezone(self::TIMEZONE)->addHours(self::OPERATOR_HOURS);
    }

    public function adminWindowStart(Irrigation $irrigation): ?Carbon
    {
        $deadline = $this->operatorWindowEnd($irrigation);
        $confirmedAt = $irrigation->operator_confirmed_at?->copy()->setTimezone(self::TIMEZONE);

        if ($deadline === null) {
            return $confirmedAt;
        }

        return $confirmedAt !== null && $confirmedAt->lessThanOrEqualTo($deadline)
            ? $confirmedAt->min($deadline)
            : $deadline;
    }

    public function adminWindowEnd(Irrigation $irrigation): ?Carbon
    {
        return $this->adminWindowStart($irrigation)?->copy()->addHours(self::ADMIN_HOURS);
    }

    public function isFinal(Irrigation $irrigation): bool
    {
        if ($this->isAdminConfirmed($irrigation) || $irrigation->finalized_at !== null) {
            return true;
        }

        $deadline = $this->adminWindowEnd($irrigation);
        return $deadline !== null && $this->now()->greaterThanOrEqualTo($deadline);
    }

    public function canOperatorEdit(Irrigation $irrigation): bool
    {
        if ($this->isFinal($irrigation) || $irrigation->operator_confirmed_at !== null) {
            return false;
        }

        $deadline = $this->operatorWindowEnd($irrigation);
        return $deadline === null || $this->now()->lessThanOrEqualTo($deadline);
    }

    public function canAdminEdit(Irrigation $irrigation): bool
    {
        if ($this->isFinal($irrigation)) {
            return false;
        }

        $start = $this->adminWindowStart($irrigation);
        $end = $this->adminWindowEnd($irrigation);
        $now = $this->now();

        return $start !== null && $end !== null
            && $now->greaterThanOrEqualTo($start)
            && $now->lessThan($end);
    }

    public function canOperatorConfirm(Irrigation $irrigation): bool
    {
        return $this->canOperatorEdit($irrigation);
    }

    public function canAdminConfirm(Irrigation $irrigation): bool
    {
        return $this->canAdminEdit($irrigation);
    }

    public function reportEligible(Irrigation $irrigation): bool
    {
        return $this->isAdminConfirmed($irrigation)
            && $irrigation->status === 'finished'
            && $irrigation->start_time !== null
            && $irrigation->end_time !== null
            && $irrigation->end_time->greaterThan($irrigation->start_time);
    }

    public function confirmOperator(Irrigation $irrigation, User $user): void
    {
        $now = $this->now();
        $irrigation->forceFill([
            'operator_confirmed_at' => $now,
            'operator_confirmed_by' => $user->id,
        ])->save();
    }

    public function confirmAdmin(Irrigation $irrigation, User $user): void
    {
        $irrigation->forceFill([
            'is_verified_by_admin' => true,
            'admin_confirmed_at' => $this->now(),
            'admin_confirmed_by' => $user->id,
            'finalized_at' => $this->now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    public function payload(Irrigation $irrigation): array
    {
        $format = static fn (?Carbon $value): ?string => $value?->copy()->setTimezone(self::TIMEZONE)->toIso8601String();
        $operatorEnd = $this->operatorWindowEnd($irrigation);
        $adminStart = $this->adminWindowStart($irrigation);
        $adminEnd = $this->adminWindowEnd($irrigation);

        return [
            'operator_confirmed' => $irrigation->operator_confirmed_at !== null,
            'operator_confirmed_at' => $format($irrigation->operator_confirmed_at),
            'operator_window_end' => $format($operatorEnd),
            'admin_window_start' => $format($adminStart),
            'admin_window_end' => $format($adminEnd),
            'admin_confirmed' => $this->isAdminConfirmed($irrigation),
            'admin_confirmed_at' => $format($irrigation->admin_confirmed_at),
            'finalized_at' => $format($irrigation->finalized_at),
            'is_final' => $this->isFinal($irrigation),
            'report_eligible' => $this->reportEligible($irrigation),
        ];
    }
}
