<?php

namespace App\Support\Pricing;

use App\Models\Setting;
use App\Models\User;

/**
 * Discount limits (upgrade brief 4 Nov, section 10).
 *
 * Business settings › VAT & quotations › "Discount limits per role" holds one
 * row per role with the most it may take off the price list (empty % = no limit).
 * A person with several roles gets the most generous one. Above the limit the
 * price waits for a manager's OK, even when the general price-approval switch is off.
 *
 * With no rows (or for a role without a row) the single limit from before
 * 4 Nov applies: "Most an agent can take off", where people with "Pricing"
 * rights are not limited and the general switch decides refuse or wait.
 */
class Discounts
{
    /** Role rows are set (the per-role rule applies). */
    public static function usesRoleLimits(): bool
    {
        return static::rows() !== [];
    }

    /** @return array<string, float|null> role => percent (null = no limit) */
    public static function rows(): array
    {
        $rows = Setting::read('discount_limits', []);

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($r) => is_array($r) && filled($r['role'] ?? null))
            ->mapWithKeys(fn ($r) => [(string) $r['role'] => isset($r['percent']) && $r['percent'] !== '' ? (float) $r['percent'] : null])
            ->all();
    }

    /**
     * The most this person may take off, in %, and whether going above it waits for a
     * manager (true) or is refused (false). [null, …] = no limit.
     *
     * @return array{0: float|null, 1: bool}
     */
    public static function limitFor(?User $user): array
    {
        if (! $user || $user->hasRole('Super Admin')) {
            return [null, false];
        }

        $rows = static::rows();
        $found = [];
        // 5 Nov: old role names count as the new ones (Sales / Staff = Agent, Manager = Admin…).
        foreach (\App\Support\Access::rolesOf($user) as $role) {
            if (array_key_exists($role, $rows)) {
                if ($rows[$role] === null) {
                    return [null, true];
                }
                $found[] = $rows[$role];
            }
        }
        if ($found) {
            return [max($found), true];
        }

        // Before 4 Nov: one limit for everyone without "Pricing" rights.
        $max = Setting::read('price_max_discount_percent');
        if ($max === null || $max === '' || $user->can('manage-pricing')) {
            return [null, false];
        }

        return [(float) $max, (bool) Setting::read('price_approval_required')];
    }

    /** A discount up to this % needs no reason; empty = every change needs a reason. */
    public static function reasonFrom(): ?float
    {
        $v = Setting::read('discount_reason_from_percent');

        return $v === null || $v === '' ? null : (float) $v;
    }

    public static function stacksWithRules(): bool
    {
        return (bool) Setting::read('discount_stacks_with_rules', false);
    }

    /** % off the price list (negative when the price is higher). */
    public static function percentOff(float $list, float $net): float
    {
        return $list > 0 ? round(($list - $net) / $list * 100, 2) : 0.0;
    }

    /** Amount after taking a % off, rounded to fils. */
    public static function apply(float $amount, ?float $percent): float
    {
        return $percent ? round($amount * (1 - min(100, max(0, $percent)) / 100), 2) : round($amount, 2);
    }

    public static function percentText(?float $p): string
    {
        return $p === null ? '' : rtrim(rtrim(number_format($p, 2), '0'), '.').'%';
    }
}
