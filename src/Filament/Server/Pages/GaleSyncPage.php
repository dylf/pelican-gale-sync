<?php

namespace Dylf\GaleSync\Filament\Server\Pages;

use App\Facades\Activity;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use Boy132\PlayerCounter\Extensions\Query\QueryTypeService;
use Boy132\PlayerCounter\Models\GameQuery;
use Dylf\GaleSync\Core\ProfileId;
use Dylf\GaleSync\Core\SyncState;
use Dylf\GaleSync\Models\GaleSyncServer;
use Dylf\GaleSync\Services\AutoApply;
use Dylf\GaleSync\Services\GaleSyncException;
use Dylf\GaleSync\Services\ProgressStore;
use Dylf\GaleSync\Services\ServerPower;
use Dylf\GaleSync\Services\SyncService;
use Dylf\GaleSync\Support\StatusRenderer;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class GaleSyncPage extends ServerFormPage
{
    public const FEATURE = 'gale_sync';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-refresh';

    protected static ?string $slug = 'gale-sync';

    protected static ?int $navigationSort = 32;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && in_array(self::FEATURE, $server->egg->features ?? [], true)
            && user()?->can('gale_sync.update', $server);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('gale_sync.update', $this->getRecord()), 403);
    }

    public static function getNavigationLabel(): string
    {
        return trans('gale-sync::messages.title');
    }

    public function getTitle(): string
    {
        return trans('gale-sync::messages.title');
    }

    protected function setting(): GaleSyncServer
    {
        return GaleSyncServer::firstOrNew(['server_id' => $this->getRecord()->id]);
    }

    protected function fillForm(): void
    {
        $setting = $this->setting();

        $this->form->fill([
            'profile' => $setting->profile,
            'skip_client_only' => $setting->exists ? $setting->skip_client_only : true,
            'config_mode' => $setting->config_mode ?: 'missing',
            'auto_apply_stopped' => $setting->auto_apply_stopped,
            'auto_apply_empty' => $setting->auto_apply_empty,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label(trans('gale-sync::messages.check_now'))
                ->icon('tabler-search')
                ->color('gray')
                ->button()
                ->disabled(fn () => !$this->setting()->profileId() || $this->syncState()->isActive())
                ->action(fn () => $this->checkNow()),

            Action::make('sync')
                ->label(trans('gale-sync::messages.sync_now'))
                ->icon('tabler-refresh')
                ->button()
                ->requiresConfirmation()
                ->modalHeading(trans('gale-sync::messages.sync_now'))
                ->modalDescription(fn () => trans($this->isRunning() ? 'gale-sync::messages.sync_confirm_running' : 'gale-sync::messages.sync_confirm_stopped'))
                ->disabled(fn () => !$this->setting()->profileId() || $this->syncState()->isActive())
                ->action(fn () => $this->syncNow()),
        ];
    }

    protected function syncState(): SyncState
    {
        $setting = $this->setting();

        return $setting->exists ? app(ProgressStore::class)->state($setting->id) : SyncState::Idle;
    }

    protected function isRunning(): bool
    {
        try {
            return !app(ServerPower::class)->isStopped($this->getRecord());
        } catch (Exception) {
            return false;
        }
    }

    public function form(Schema $schema): Schema
    {
        $hasPlayerCounter = class_exists(GameQuery::class) && app()->bound(QueryTypeService::class);

        return $schema
            ->statePath('data')
            ->components([
                // First on the page, so what the sync is doing is the first thing you see.
                Html::make(fn () => $this->statusHtml()),

                Section::make(trans('gale-sync::messages.profile_section'))
                    ->description(trans('gale-sync::messages.profile_section_hint'))
                    ->schema([
                        TextInput::make('profile')
                            ->label(trans('gale-sync::messages.profile'))
                            ->helperText(trans('gale-sync::messages.profile_hint'))
                            ->placeholder('Ab12Cd')
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && !ProfileId::parse((string) $value)) {
                                    $fail(trans('gale-sync::messages.profile_invalid'));
                                }
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn () => $this->save()),
                    ]),

                Section::make(trans('gale-sync::messages.options_section'))
                    ->schema([
                        Toggle::make('skip_client_only')
                            ->label(trans('gale-sync::messages.skip_client_only'))
                            ->helperText(trans('gale-sync::messages.skip_client_only_hint'))
                            ->live()
                            ->afterStateUpdated(fn () => $this->save()),

                        Select::make('config_mode')
                            ->label(trans('gale-sync::messages.config_mode'))
                            ->helperText(trans('gale-sync::messages.config_mode_hint'))
                            ->options([
                                'none' => trans('gale-sync::messages.config_none'),
                                'missing' => trans('gale-sync::messages.config_missing'),
                                'overwrite' => trans('gale-sync::messages.config_overwrite'),
                            ])
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->save()),
                    ]),

                Section::make(trans('gale-sync::messages.auto_section'))
                    ->description(trans('gale-sync::messages.auto_section_hint'))
                    ->schema([
                        Toggle::make('auto_apply_stopped')
                            ->label(trans('gale-sync::messages.auto_stopped'))
                            ->helperText(trans('gale-sync::messages.auto_stopped_hint'))
                            ->live()
                            ->afterStateUpdated(fn () => $this->save()),

                        Toggle::make('auto_apply_empty')
                            ->label(trans('gale-sync::messages.auto_empty'))
                            ->helperText(trans($hasPlayerCounter ? 'gale-sync::messages.auto_empty_hint' : 'gale-sync::messages.auto_empty_needs_player_counter'))
                            ->disabled(!$hasPlayerCounter)
                            ->live()
                            ->afterStateUpdated(fn () => $this->save()),

                        Text::make(trans('gale-sync::messages.auto_manual_note')),
                    ]),

            ]);
    }

    protected function statusHtml(): HtmlString
    {
        $setting = $this->setting();
        $when = fn ($at) => $at ? $at->diffForHumans() : trans('gale-sync::messages.never');

        $renderer = new StatusRenderer(fn (string $key, array $values) => trans('gale-sync::messages.' . $key, $values), time());

        $progress = $setting->exists ? app(ProgressStore::class)->get($setting->id) : null;

        return new HtmlString($renderer->render([
            'has_profile' => (bool) $setting->profile,
            'state' => $this->syncState(),
            'last_error' => $setting->last_error,
            'last_checked' => $when($setting->last_checked_at),
            'last_synced' => $setting->last_synced_at ? $when($setting->last_synced_at) : null,
            'pending' => $setting->pending,
            'progress' => $progress,
        ]));
    }

    public function save(): void
    {
        abort_unless(user()?->can('gale_sync.update', $this->getRecord()), 403);

        try {
            $data = $this->form->getState();

            $profile = filled($data['profile'] ?? null) ? trim((string) $data['profile']) : null;
            $setting = $this->setting();
            $changed = $setting->profile !== $profile;

            $setting->fill([
                'profile' => $profile,
                'skip_client_only' => (bool) ($data['skip_client_only'] ?? true),
                'config_mode' => in_array($data['config_mode'] ?? null, ['none', 'missing', 'overwrite'], true) ? $data['config_mode'] : 'missing',
                'auto_apply_stopped' => (bool) ($data['auto_apply_stopped'] ?? false),
                'auto_apply_empty' => (bool) ($data['auto_apply_empty'] ?? false),
            ]);

            // Another profile or other options make the last look at the profile out of date.
            if ($changed || $setting->isDirty(['skip_client_only'])) {
                $setting->fill(['pending' => null, 'remote_updated_at' => null, 'last_error' => null, 'last_checked_at' => null]);
            }

            $setting->save();

            Activity::event('server:gale-sync.update')
                ->property('profile', $profile)
                ->property('auto_apply_stopped', $setting->auto_apply_stopped)
                ->property('auto_apply_empty', $setting->auto_apply_empty)
                ->log();

            Notification::make()
                ->title(trans('gale-sync::messages.saved'))
                ->success()
                ->send();

            if ($changed && $profile) {
                $this->checkNow();
            }
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('gale-sync::messages.save_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function checkNow(): void
    {
        abort_unless(user()?->can('gale_sync.update', $this->getRecord()), 403);

        $setting = $this->setting();

        if (!$setting->exists || !$setting->profileId()) {
            return;
        }

        app(SyncService::class)->check($setting, force: true);
        $setting->refresh();

        $notification = Notification::make();

        if ($setting->last_error) {
            $notification->title(trans('gale-sync::messages.check_failed'))->body($setting->last_error)->danger();
        } else {
            $notification->title(trans(SyncService::hasPending($setting->pending) ? 'gale-sync::messages.check_found_changes' : 'gale-sync::messages.up_to_date'))->success();
        }

        $notification->send();
    }

    public function syncNow(): void
    {
        abort_unless(user()?->can('gale_sync.update', $this->getRecord()), 403);

        $setting = $this->setting();

        if (!$setting->exists || !$setting->profileId()) {
            return;
        }

        if ($this->syncState()->isActive()) {
            Notification::make()->title(trans('gale-sync::messages.already_syncing'))->warning()->send();

            return;
        }

        try {
            $running = $this->isRunning();
        } catch (GaleSyncException) {
            $running = false;
        }

        // A running server is stopped for the sync and started again after it. The scheduled command does the work.
        AutoApply::request($setting, stopFirst: $running, startAfter: $running);

        Activity::event('server:gale-sync.sync')
            ->property('profile', $setting->profile)
            ->log();

        Notification::make()
            ->title(trans('gale-sync::messages.sync_started'))
            ->success()
            ->send();
    }
}
