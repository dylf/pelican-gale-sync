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
 * @property string $status idle, syncing or failed
 * @property ?CarbonInterface $status_since
 * @property ?string $status_message
 * @property ?CarbonInterface $last_synced_at
 * @property Server $server
 */
class GaleSyncServer extends Model
{
    public const IDLE = 'idle';

    public const SYNCING = 'syncing';

    public const FAILED = 'failed';

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
        'status',
        'status_since',
        'status_message',
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
            'status_since' => 'datetime',
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

    public function isSyncing(): bool
    {
        if ($this->status !== self::SYNCING) {
            return false;
        }

        // A job that died without finishing must not block the server for ever.
        return $this->status_since && $this->status_since->gt(now()->subMinutes((int) config('gale-sync.stale_minutes', 30)));
    }

    public function setStatus(string $status, ?string $message = null): void
    {
        $this->forceFill(['status' => $status, 'status_since' => now(), 'status_message' => $message])->save();
    }
}
