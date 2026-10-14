<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Auth\PendingEmailChange;
use App\Services\Technicians\TechnicianPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /** El técnico carga o reemplaza su foto de perfil (Etapa 8.2). */
    public function updatePhoto(Request $request, TechnicianPhotoService $photos): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file']], ['photo.required' => 'Elegí una foto.']);
        $photos->replace($request->user(), $request->user(), $request->file('photo'));

        return Redirect::route('profile.edit')->with('status', 'photo-updated');
    }

    /** El técnico quita su foto: se vuelven a mostrar sus iniciales. */
    public function destroyPhoto(Request $request, TechnicianPhotoService $photos): RedirectResponse
    {
        $photos->remove($request->user(), $request->user());

        return Redirect::route('profile.edit')->with('status', 'photo-removed');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, PendingEmailChange $emailChanges): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name']))->save();

        // El correo no cambia acá: queda pendiente hasta que se confirme desde
        // la dirección nueva (Etapa 11). Mismo correo = cancela lo pendiente.
        $email = (string) $request->validated('email');
        $changing = mb_strtolower($email) !== mb_strtolower((string) $user->email);
        $emailChanges->request($user, $email);

        return Redirect::route('profile.edit', [
            'company' => $user->company->slug,
        ])->with('status', $changing ? 'email-pending' : 'profile-updated');
    }

    public function cancelEmailChange(Request $request, PendingEmailChange $emailChanges): RedirectResponse
    {
        $emailChanges->cancel($request->user());

        return Redirect::route('profile.edit', ['company' => $request->user()->company->slug])->with('status', 'email-change-canceled');
    }
}
