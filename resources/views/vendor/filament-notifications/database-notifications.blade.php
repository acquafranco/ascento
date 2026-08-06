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

            @foreach ($notifications as $notification)
                <div
                    @class([
                        'fi-no-notification-read-ctn' => ! $notification->unread(),
                        'fi-no-notification-unread-ctn' => $notification->unread(),
                    ])
                >
    @php
        $n = $this->getNotification($notification);

        $data = $notification->data;

        $priority = $data['priority'] ?? null;

        $priorityColors = [
            'baja' => 'success',
            'media' => 'warning',
            'alta' => 'danger',
            'critica' => 'gray',
            'crítica' => 'gray',
            'low' => 'success',
            'medium' => 'warning',
            'high' => 'danger',
            'critical' => 'gray',
        ];

        $priorityLabels = [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'critica' => 'Crítica',
            'crítica' => 'Crítica',
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            'critical' => 'Crítica',
        ];
    @endphp

    <div class="fi-no-notification fi-inline" style="display:flex;visibility:visible;">
        <div class="fi-no-notification-main" style="width:100%;">
            <div class="fi-no-notification-text">
                <h3 class="fi-no-notification-title">
                    {{ $n->getTitle() }}
                </h3>

                <div class="fi-no-notification-body">
                    {{ $n->getBody() }}
                </div>

                @if(isset($data['report_id']))
                    <div style="margin-top:12px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <x-filament::button
                            tag="a"
                            href="{{ route('filament.ascensores_app.resources.reports.view', ['record' => $data['report_id']]) }}"
                            size="sm"
                            color="primary"
                            icon="heroicon-m-eye"
                        >
                            Ver reporte
                        </x-filament::button>

                        @if($priority)
                            <span style="
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:90px;
    padding:6px 14px;
    border-radius:9999px;
    font-size:0.875rem;
    font-weight:600;
    line-height:1.25rem;
    background-color:
        {{ match($priority) {
            'baja' => 'rgb(34 197 94)',
            'media' => 'rgb(234 179 8)',
            'alta' => 'rgb(239 68 68)',
            'critica', 'crítica' => 'rgb(168 85 247)',
            default => 'rgb(107 114 128)',
        } }};
    color:white;
    box-shadow:0 1px 2px rgba(0,0,0,.15);
">
    {{ $priorityLabels[$priority] ?? ucfirst($priority) }}
</span>
                        @endif
                    </div>
                @elseif($priority)
                    <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                        <span style="
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:90px;
    padding:6px 14px;
    border-radius:9999px;
    font-size:0.875rem;
    font-weight:600;
    line-height:1.25rem;
    background-color:
        {{ match($priority) {
            'baja' => 'rgb(34 197 94)',
            'media' => 'rgb(234 179 8)',
            'alta' => 'rgb(239 68 68)',
            'critica', 'crítica' => 'rgb(168 85 247)',
            default => 'rgb(107 114 128)',
        } }};
    color:white;
    box-shadow:0 1px 2px rgba(0,0,0,.15);
">
    {{ $priorityLabels[$priority] ?? ucfirst($priority) }}
</span>
                    </div>
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
