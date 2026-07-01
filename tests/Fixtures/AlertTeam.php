<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Traits\UsesHealthChecks;

/**
 * A notifiable used in tests as an alerts target for certificate expiry checks.
 *
 * @property int $id
 * @property string $name
 */
final class AlertTeam extends Model implements HasNotifiablesForAlerts
{
    use Notifiable;
    use UsesHealthChecks;

    protected $guarded = [];

    protected $table = 'alert_teams';

    public function forEachNotifiableForAlerts(Closure $callback): void
    {
        $callback($this);
    }
}
