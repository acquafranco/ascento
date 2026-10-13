<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
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
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit', [
            'company' => auth()->user()->company->slug,
        ])->with('status', 'profile-updated');
    }
}
