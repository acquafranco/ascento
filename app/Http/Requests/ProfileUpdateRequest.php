<?php

namespace App\Http\Requests;

use App\Rules\UniqueUserEmail;
use App\Support\Auth\UserEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function emailChanges(): bool
    {
        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()->email);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Para cambiar el correo, escribí tu contraseña actual.',
            'current_password.current_password' => 'La contraseña no es correcta.',
        ];
    }

    /** Mismo correo con otras mayúsculas o espacios = la misma cuenta. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => UserEmail::normalize((string) $this->input('email'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueUserEmail($this->user()->id),
            ],
            // Cambiar el correo cambia a dónde llega «Olvidé mi contraseña»:
            // con una sesión robada alcanzaba para tomar la cuenta. Se pide la
            // contraseña actual solo si el correo cambia (Etapa 11, R8).
            'current_password' => [
                Rule::requiredIf(fn () => $this->emailChanges()),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }
}
