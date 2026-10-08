{{-- Config de avisos push del admin (clave PÚBLICA VAPID) + script. --}}
@php($slug = auth()->user()->company->slug)
<meta name="ascento-push" content="{{ json_encode([
    'userId' => auth()->id(),
    'vapidPublicKey' => config('webpush.vapid.public_key'),
    'storeUrl' => route('push-subscriptions.store', ['company' => $slug]),
    'destroyUrl' => route('push-subscriptions.destroy', ['company' => $slug]),
    'testUrl' => route('push-subscriptions.test', ['company' => $slug]),
    'successMessage' => 'Listo. Te vamos a avisar cuando terminen un trabajo o carguen un reporte.',
]) }}">
<link rel="manifest" href="/manifest.webmanifest">
@vite(['resources/js/admin-push.js'])
