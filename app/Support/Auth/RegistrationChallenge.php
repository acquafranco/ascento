<?php

namespace App\Support\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Protección del registro de empresas contra altas automáticas, sin servicios
 * externos. Tres capas, todas resueltas en el servidor:
 *
 * 1. Campo trampa (honeypot): un campo que una persona no ve ni completa.
 * 2. Tiempo mínimo: desde que se cargó el formulario (guardado en la sesión,
 *    el navegador no lo puede adelantar).
 * 3. Desafío: el indicador de piso de un ascensor (dígitos de 7 segmentos,
 *    dibujados con ruido) y botones de pisos mezclados; hay que tocar el que
 *    coincide. Cada botón manda un token al azar (no el número). Alternativa
 *    accesible: una pregunta en palabras ("está en el piso tres y sube dos").
 *    - vence a los 15 minutos, es de un solo uso y admite 5 intentos;
 *    - en la sesión solo queda el HMAC de las respuestas;
 *    - resuelto bien, vale 15 minutos aunque falte corregir otro campo.
 *
 * Límites honestos: frena registros automáticos genéricos; un ataque dirigido
 * con lectura de imágenes o de texto lo puede resolver. Por eso se suma al
 * límite de 6 intentos por minuto de la ruta y a la confirmación del correo
 * (sin correo confirmado no se pueden mandar correos a terceros: VerifiedSender).
 */
final class RegistrationChallenge
{
    public const MIN_SECONDS = 4;

    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const HONEYPOT = 'website';

    private const KEY = 'register.challenge';

    private const STARTED = 'register.started_at';

    private const PASSED = 'register.challenge_passed_until';

    private const WORDS = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce'];

    /** Al mostrar el formulario: arranca el reloj y deja un desafío vigente. */
    public static function prepare(Request $request): array
    {
        $session = $request->session();
        $session->put(self::STARTED, now()->timestamp);

        $challenge = $session->get(self::KEY);
        if (! is_array($challenge) || ($challenge['expires'] ?? 0) < now()->timestamp || ($challenge['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $challenge = self::fresh($request);
        }

        return self::view($request, $challenge);
    }

    /**
     * Campo trampa y tiempo mínimo, antes de validar el resto.
     *
     * @throws ValidationException
     */
    public static function ensureHuman(Request $request): void
    {
        $started = (int) $request->session()->get(self::STARTED, 0);

        if (filled($request->input(self::HONEYPOT))) {
            throw ValidationException::withMessages(['form' => 'No pudimos procesar el registro. Volvé a cargar la página e intentá de nuevo.']);
        }

        if ($started === 0) {
            throw ValidationException::withMessages(['form' => 'La página venció. Revisá los datos y volvé a enviar el formulario.']);
        }

        if (now()->timestamp - $started < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['form' => 'Completaste el formulario muy rápido. Revisá los datos y volvé a enviarlo.']);
        }
    }

    /**
     * Regla de validación del desafío (se cuenta un intento por envío).
     * Implícita: corre aunque el campo venga vacío.
     */
    public static function rule(Request $request): ValidationRule
    {
        return new class($request) implements ValidationRule
        {
            public bool $implicit = true;

            public function __construct(private Request $request) {}

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $error = RegistrationChallenge::check($this->request);
                if ($error !== null) {
                    $fail($error);
                }
            }
        };
    }

    /** Registro creado: se consume todo (un solo uso). */
    public static function complete(Request $request): void
    {
        $request->session()->forget([self::KEY, self::STARTED, self::PASSED]);
    }

    /** Lo resuelto deja de valer (el ingreso lo pide en cada intento: ver AccountAttackGuard). */
    public static function forgetPassed(Request $request): void
    {
        $request->session()->forget(self::PASSED);
    }

    public static function passed(Request $request): bool
    {
        return (int) $request->session()->get(self::PASSED, 0) >= now()->timestamp;
    }

    /** @internal usar rule() */
    public static function check(Request $request): ?string
    {
        if (self::passed($request)) {
            return null;
        }

        $session = $request->session();
        $challenge = $session->get(self::KEY);

        if (! is_array($challenge) || ($challenge['expires'] ?? 0) < now()->timestamp) {
            self::fresh($request);

            return 'El desafío venció. Resolvé el nuevo.';
        }

        $challenge['attempts'] = ($challenge['attempts'] ?? 0) + 1;
        $session->put(self::KEY, $challenge);

        $token = (string) $request->input('challenge', '');
        $text = trim((string) $request->input('challenge_text', ''));
        $ok = ($token !== '' && hash_equals($challenge['visual'], self::hmac($token)))
            || ($text !== '' && hash_equals($challenge['text'], self::hmac(self::normalizeNumber($text))));

        if ($ok) {
            $session->forget(self::KEY);
            $session->put(self::PASSED, now()->addMinutes(self::TTL_MINUTES)->timestamp);

            return null;
        }

        if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
            self::fresh($request);

            return 'Demasiados intentos. Resolvé el desafío nuevo.';
        }

        return ($token === '' && $text === '')
            ? 'Tocá el botón del piso que muestra el indicador (o respondé la pregunta de abajo).'
            : 'La respuesta no es correcta. Probá de nuevo.';
    }

    /** Nuevo desafío: el piso del indicador, los botones y la pregunta en palabras. */
    private static function fresh(Request $request): array
    {
        $digits = collect(range(1, 9))->shuffle()->take(6)->values();
        $target = $digits->random();
        $buttons = $digits->shuffle()->map(fn ($digit) => ['digit' => $digit, 'token' => Str::random(24)])->values()->all();

        $start = random_int(2, 8);
        $up = (bool) random_int(0, 1);
        $moves = random_int(1, $up ? min(4, 12 - $start) : min(4, $start - 1));
        $answer = $up ? $start + $moves : $start - $moves;

        $challenge = [
            'visual' => self::hmac(collect($buttons)->firstWhere('digit', $target)['token']),
            'text' => self::hmac((string) $answer),
            'expires' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
            'attempts' => 0,
            // Para dibujarlo (la sesión es del servidor; el navegador no la ve).
            'target' => $target,
            'buttons' => $buttons,
            'question' => 'El ascensor está en el piso '.self::WORDS[$start].' y '.($up ? 'sube ' : 'baja ').($moves === 1 ? 'un piso' : self::WORDS[$moves].' pisos').'. ¿En qué piso queda?',
        ];

        $request->session()->put(self::KEY, $challenge);

        return $challenge;
    }

    /** @return array{passed: bool, display: string, buttons: list<array{token: string, svg: string}>, question: string} */
    private static function view(Request $request, array $challenge): array
    {
        return [
            'passed' => self::passed($request),
            'display' => SevenSegment::display((int) $challenge['target']),
            'buttons' => collect($challenge['buttons'])->map(fn ($b) => ['token' => $b['token'], 'svg' => SevenSegment::button((int) $b['digit'])])->all(),
            'question' => $challenge['question'],
        ];
    }

    private static function normalizeNumber(string $text): string
    {
        $text = Str::lower(Str::ascii(trim($text)));
        $index = array_search($text, self::WORDS, true);

        return $index !== false ? (string) $index : (string) (int) preg_replace('/\D+/', '', $text);
    }

    private static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
