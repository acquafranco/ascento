<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
return \App\Support\HomeRedirect::to();
        }

        // Además del límite por minuto de la ruta: 3 enlaces cada 10 minutos por cuenta.
        $key = 'verification-send:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('status', 'verification-throttled');
        }

        RateLimiter::hit($key, 600);
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
