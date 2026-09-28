<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * Soft-delete registry rows untouched for `$days` days. Without a status only dead ends
 * (expired, failed, revoked) are pruned; pass one to prune exactly that status.
 *
 * Reach it through `Certificates::prune()` (what `certificates:prune` runs).
 */
final readonly class PruneCertificatesAction
{
    /**
     * @return int how many rows were soft-deleted
     */
    public function execute(int $days = 30, CertificateStatus|string|null $status = null, ?string $connection = null): int
    {
        $query = CertificateModel::class()::on($connection)
            ->where('updated_at', '<=', CarbonImmutable::now()->subDays($days));

        if ($status !== null) {
            $query->where('status', $status instanceof CertificateStatus ? $status->value : $status);
        } else {
            $query->whereIn('status', [
                CertificateStatus::Expired->value,
                CertificateStatus::Failed->value,
                CertificateStatus::Revoked->value,
            ]);
        }

        $count = 0;

        foreach ($query->get() as $certificate) {
            $certificate->delete();
            $count++;
        }

        return $count;
    }
}
