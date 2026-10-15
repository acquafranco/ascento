<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <header class="mb-4">
        <h1 class="t-h1">Mi perfil</h1>
        <p class="t-sub">Tus datos, tu contraseña y los avisos en este dispositivo.</p>
    </header>

    <div class="space-y-4">
        @if (auth()->user()->canReceiveWorkOrderPush())
            <div class="space-y-4" id="notificaciones">
                @include('push-notifications.card')
                @include('push-notifications.telegram')
            </div>
        @endif

        @if ($user->role === 'technician')
            <section class="t-card p-4 sm:p-6">
                @include('profile.partials.photo')
            </section>
        @endif

        @if (\App\Services\Location\TechnicianLocationService::canTrack($user))
            <div class="t-card p-4 sm:p-6">
                @include('profile.partials.location')
            </div>
        @endif

        <section class="t-card p-4 sm:p-6">
            @include('profile.partials.update-profile-information-form')
        </section>

        <section class="t-card p-4 sm:p-6">
            @include('profile.partials.update-password-form')
        </section>

        <section class="t-card p-4 sm:p-6">
            @include('profile.partials.account-status')
        </section>
    </div>
</div>
</x-app-layout>
