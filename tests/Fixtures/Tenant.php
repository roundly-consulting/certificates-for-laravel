<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Certificates\Concerns\HasCertificates;

/**
 * @property int $id
 * @property string $name
 */
final class Tenant extends Model
{
    use HasCertificates;

    protected $guarded = [];

    protected $table = 'tenants';
}
