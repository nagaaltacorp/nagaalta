<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ManagerBranchScope
{
    /**
     * @return array<int>|null null = no scope (admin / guest), array = manager branch IDs
     */
    public static function branchIdsFor(?User $user): ?array
    {
        if (!$user || $user->role !== 'manager') {
            return null;
        }

        return Branch::query()
            ->where('manager_user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function appliesTo(?User $user): bool
    {
        return $user !== null && $user->role === 'manager';
    }

    public static function ensureBranchAllowed(?User $user, int $branchId): bool
    {
        $ids = self::branchIdsFor($user);

        if ($ids === null) {
            return true;
        }

        return in_array($branchId, $ids, true);
    }

    public static function scopeInventories(Builder $query, ?User $user): Builder
    {
        $ids = self::branchIdsFor($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('branch_id', $ids);
    }

    public static function scopeBranches(Builder $query, ?User $user): Builder
    {
        $ids = self::branchIdsFor($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('id', $ids);
    }

    public static function scopeSales(Builder $query, ?User $user): Builder
    {
        $ids = self::branchIdsFor($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereHas('processedBy.employee', function (Builder $employeeQuery) use ($ids) {
            $employeeQuery->whereIn('branch_id', $ids);
        });
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function constrainJoinedSales($query, ?array $branchIds)
    {
        if ($branchIds === null) {
            return $query;
        }

        return $query->whereIn('employees.branch_id', $branchIds);
    }
}
