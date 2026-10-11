@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div class="fi-no-database">
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        close-button
        :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
        :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
        :icon="$hasNotifications ? null : \Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        :icon-alias="
            $hasNotifications
            ? null
            : \Filament\Notifications\View\NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE
        "
        :icon-color="$hasNotifications ? null : 'gray'"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new \Illuminate\View\ComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div>
                    <h2 class="fi-modal-heading">
                        {{ __('filament-notifications::database.modal.heading') }}

                        @if ($unreadNotificationsCount)
                            <span
                                {{
                                    (new ComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    <div class="fi-ac">
                        @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        @if ($this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif
                    </div>
                </div>
            </x-slot>

            {{--
                Cada aviso (ver App\Livewire\DatabaseNotifications):
                - "Abrir" pasa por /notificaciones/{id}: marca leído en el servidor y redirige.
                - "Marcar como leída" en todos los que no se leyeron (también sin destino).
                - data-bell-notification / data-notification-unread: los usa el "visto al cerrar"
                  (filament/partials/notifications-seen).
            --}}
            @foreach ($notifications as $notification)
                @php
                    $n = $this->getNotification($notification);
                    $data = $notification->data;
                    $unread = $notification->unread();
                    $priority = $data['priority'] ?? null;
                    $priorityLabels = [
                        'baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica', 'crítica' => 'Crítica',
                        'low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica',
                    ];
                    $priorityColor = match ($priority) {
                        'baja', 'low' => 'rgb(34 197 94)',
                        'media', 'medium' => 'rgb(234 179 8)',
                        'alta', 'high' => 'rgb(239 68 68)',
                        'critica', 'crítica', 'critical' => 'rgb(168 85 247)',
                        default => 'rgb(107 114 128)',
                    };
                    $hasDestination = \App\Http\Controllers\NotificationInboxController::destinationFor($notification) !== null;
                    $openLabel = isset($data['report_id']) ? 'Ver reporte' : (collect($data['actions'] ?? [])->pluck('label')->filter()->first() ?? 'Abrir');
                @endphp
                <div
                    wire:key="bell-{{ $notification->getKey() }}"
                    data-bell-notification
                    data-notification-id="{{ $notification->getKey() }}"
                    @if ($unread) data-notification-unread @endif
                    @class([
                        'fi-no-notification-read-ctn' => ! $unread,
                        'fi-no-notification-unread-ctn' => $unread,
                    ])
                >
                    <div class="fi-no-notification fi-inline" style="display:flex;visibility:visible;">
                        <div class="fi-no-notification-main" style="width:100%;">
                            <div class="fi-no-notification-text">
                                <h3 class="fi-no-notification-title">
                                    @if ($unread)<span class="fi-sr-only">{{ __('filament-notifications::database.modal.unread_label') }}</span>@endif
                                    {{ $n->getTitle() }}
                                </h3>
                                <time class="fi-no-notification-date">{{ $notification->created_at?->diffForHumans() }}</time>
                                @if (filled($n->getBody()))
                                    <div class="fi-no-notification-body">{{ $n->getBody() }}</div>
                                @endif
                            </div>

                            <div style="margin-top:10px; display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px;">
                                @if ($hasDestination)
                                    <x-filament::button tag="a" :href="route('notifications.open', $notification->getKey())" size="sm" color="primary" icon="heroicon-m-eye">
                                        {{ $openLabel }}
                                    </x-filament::button>
                                @endif

                                @if ($unread)
                                    <x-filament::link tag="button" color="gray" size="sm" wire:click="markNotificationAsRead('{{ $notification->getKey() }}')">
                                        Marcar como leída
                                    </x-filament::link>
                                @endif

                                @if ($priority)
                                    <span style="margin-left:auto; display:inline-flex; align-items:center; justify-content:center; min-width:90px; padding:6px 14px; border-radius:9999px; font-size:0.875rem; font-weight:600; line-height:1.25rem; background-color: {{ $priorityColor }}; color:white; box-shadow:0 1px 2px rgba(0,0,0,.15);">
                                        {{ $priorityLabels[$priority] ?? ucfirst($priority) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>
