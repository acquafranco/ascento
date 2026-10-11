<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rechaza contraseñas fáciles de adivinar, sin consultar servicios externos:
 * - las de la lista local (resources/data/common-passwords.txt), también con
 *   números o símbolos al final ("boca2024!") o con reemplazos típicos ("p4ssw0rd");
 * - secuencias de teclado o abecedario ("qwertyui12", "abcdefgh9");
 * - las que repiten uno o dos caracteres ("aaaaaa1111");
 * - las que contienen el nombre o el correo de la persona.
 */
class NotCommonPassword implements ValidationRule
{
    public const MESSAGE = 'Esa contraseña es muy fácil de adivinar. Probá con una frase o combiná palabras que no tengan que ver con vos, más números.';

    public const PERSONAL_MESSAGE = 'La contraseña no puede contener tu nombre ni tu correo.';

    private const SEQUENCES = ['abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm', '01234567890'];

    /** @var array<string, true>|null */
    private static ?array $list = null;

    /** @param  array<int, ?string>  $personal */
    public function __construct(private array $personal = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $raw = static::normalize($value, stripTail: false);
        $base = static::normalize($value);

        // Letras y números por separado, sin reemplazos: "1234567890a", "abcdefgh12".
        $plain = (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
        $letters = (string) preg_replace('/[^a-z]/', '', $plain);
        $digits = (string) preg_replace('/\D/', '', $plain);
        $sequenceWithLittleElse = (static::isSequence($letters) && strlen($digits) <= 4) || (static::isSequence($digits) && strlen($letters) <= 2);

        if (isset(static::list()[$raw]) || ($base !== '' && isset(static::list()[$base])) || static::isSequence($base) || $sequenceWithLittleElse || count(array_unique(mb_str_split($raw))) <= 2) {
            $fail(self::MESSAGE);

            return;
        }

        foreach ($this->personalTokens() as $token) {
            if (str_contains($raw, $token)) {
                $fail(self::PERSONAL_MESSAGE);

                return;
            }
        }
    }

    /** Minúsculas, sin acentos ni espacios, reemplazos típicos y (opcional) sin el final de números/símbolos. */
    public static function normalize(string $value, bool $stripTail = true): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/\s+/', '', $value);

        if ($stripTail) {
            $value = preg_replace('/[^a-z@$]+$/', '', (string) $value);
        }

        return strtr((string) $value, ['4' => 'a', '@' => 'a', '3' => 'e', '1' => 'i', '!' => 'i', '0' => 'o', '5' => 's', '$' => 's', '7' => 't']);
    }

    private static function isSequence(string $base): bool
    {
        if (strlen($base) < 4) {
            return false;
        }

        foreach (self::SEQUENCES as $sequence) {
            if (str_contains($sequence, $base) || str_contains(strrev($sequence), $base)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function personalTokens(): array
    {
        $tokens = [];

        foreach ($this->personal as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $text = str_contains($text, '@') ? Str::before($text, '@') : $text;
            foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) as $part) {
                if (strlen($part) >= 4) {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @return array<string, true> */
    private static function list(): array
    {
        return static::$list ??= collect(file(resource_path('data/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            ->reject(fn ($line) => str_starts_with(trim($line), '#'))
            ->mapWithKeys(fn ($line) => [static::normalize(trim($line), stripTail: false) => true])
            ->all();
    }
}
