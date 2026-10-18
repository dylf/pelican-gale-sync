# Gale Sync (Pelican plugin)

Keep a game server's mods in sync with a [Gale](https://gale.kesomannen.com) profile. Point a server at your Gale
**sync ID** and it installs the same mods, at the same versions, from Thunderstore or Hexium, that your players run. When
you update the profile in Gale, the server shows the update and applies it when you say so (or by itself, if you allow it).

Works for any game Gale supports with a BepInEx, BepisLoader or MelonLoader mod loader (about 225 of Gale's 239 games).
Other loaders are reported as unsupported instead of guessed at.

## How it works
- **Follow:** the server page takes a Gale sync ID (6 or 22 characters) and checks it every few minutes. A one-time
  profile code (a UUID) also works, but it is a frozen snapshot and never updates.
- **Exact versions:** a profile pins every mod version, so the server runs exactly those. Nothing drifts to "latest".
- **Sources:** the profile records whether each mod came from Thunderstore or Hexium; the plugin downloads from there.
- **Install rules:** files go where Gale (and r2modman) would put them, e.g. `BepInEx/plugins/Owner-Name/`.
- **Safe:** it records what it installed in `.gale-sync.json` on the server and only replaces or removes those. Files you
  uploaded by hand are left alone. The mod loader itself comes from the egg and is never touched.
- **Client-only mods** (tagged client-side only) are skipped by default; they do nothing on a server.
- **Config files** from the profile can be copied: not at all, only the ones the server doesn't have, or all of them
  (so the server matches the profile). Player data (`*_player_*`, `*.dat`) and `BepInEx.cfg` are never sent.

## Updates
By default a profile update waits on the page until you press **Sync now**. Two options let a server do it by itself:
- **Apply updates when the server is stopped.** Pairs well with [Lazy Pelican](https://github.com/dylf/lazy-pelican): the
  server updates while nobody is playing, before it starts again.
- **Apply updates when the server is empty.** Stops the server, updates it and starts it again. Needs the
  [Player Counter](https://hub.pelican.dev/plugins/player-counter) plugin with a Game Query for the egg.

Remember your players have to update their profile in Gale too, or they and the server will run different mods.

## Status
The top of the server page shows what is going on, live: a banner with a progress bar while a sync runs, and every mod as
waiting, working, done or failed (with the reason when it failed). Nothing is stored that could get stuck: the state is
worked out from the sync's progress and from whether the process doing it is still reporting.
- **Requested:** waiting for the scheduler's next run (under a minute). After 5 minutes the page says it never started,
  which means the scheduler isn't running, and **Sync now** can be pressed again.
- **Running:** the process is alive and reporting. **Sync now** is off until it finishes.
- **Stopped unexpectedly:** it started but went quiet for 5 minutes (the process died). **Sync now** is available again
  and picks up where it left off, because each finished mod is recorded as it completes.
- **Finished:** the result stays for 30 minutes, then the page goes back to "up to date" or "update available".

## Setup
1. Install the plugin (Admin → Plugins → Import).
2. Add the egg feature `gale_sync` to the eggs that should get the page (Admin → Eggs → Features).
3. Open the server → **Gale Sync**, paste the sync ID.
4. Give subusers the **Gale Sync** permission (`gale_sync.update`) if they should manage it.

## Requirements
- A Pelican panel with the plugin system.
- **The panel's scheduler**, which Pelican already needs: `php artisan schedule:run` every minute. Syncs, update checks and
  automatic updates all run from it. There is no queue worker to restart after installing or updating this plugin.
- The panel must be able to reach Gale, Thunderstore and Hexium. The game server does not need internet access for this,
  and Wings' remote-download setting is not used: the panel downloads each mod and writes the files through Wings.

## Notes
- A sync needs the server stopped (mods can't change under a running game). **Sync now** on a running server stops it,
  syncs and starts it again, and a sync requested with the server stopped leaves it stopped.
- A requested sync starts within a minute, on the scheduler's next run.
- Reinstalling a server through the egg's install script wipes its mods; the next sync puts them back.
- Mods installed by hand under the same name are replaced by the profile's version.

## Config (env)
| Variable | Default |
|---|---|
| `GALE_SYNC_CHECK_INTERVAL` | 5 (minutes between looks at a profile) |
| `GALE_SYNC_CACHE_HOURS` | 12 |
| `GALE_SYNC_STOP_TIMEOUT` | 90 (seconds to wait for a server to stop) |
| `GALE_SYNC_QUEUE_WAIT_MINUTES` | 5 |

## Development
```
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```
The mod list, loader rules and sync plan live in `src/Core` and are unit tested without the panel.
