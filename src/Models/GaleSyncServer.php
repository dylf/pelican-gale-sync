<?php

namespace Dylf\GaleSync\Models;

use App\Models\Server;
use Carbon\CarbonInterface;
use Dylf\GaleSync\Core\ProfileId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-server Gale Sync settings and the result of the last check and sync.
 *
 * @property int $id
 * @property int $server_id
 * @property ?string $profile
 * @property bool $skip_client_only
 * @property string $config_mode none, missing or overwrite
 * @property bool $auto_apply_stopped
 * @property bool $auto_apply_empty
 * @property ?CarbonInterface $last_checked_at
 * @property ?string $remote_updated_at
 * @property ?array<string, mixed> $pending
 * @property ?string $last_error
 * @property ?CarbonInterface $last_synced_at
 * @property Server $server
 */
class GaleSyncServer extends Model
{
    protected $fillable = [
        'server_id',
        'profile',
        'skip_client_only',
        'config_mode',
        'auto_apply_stopped',
        'auto_apply_empty',
        'last_checked_at',
        'remote_updated_at',
        'pending',
        'last_error',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'skip_client_only' => 'bool',
            'auto_apply_stopped' => 'bool',
            'auto_apply_empty' => 'bool',
            'pending' => 'array',
            'last_checked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function profileId(): ?ProfileId
    {
        return $this->profile ? ProfileId::parse($this->profile) : null;
    }
}
