<?php

return [

    'accepted' => 'El campo :attribute debe ser aceptado.',
    'accepted_if' => 'El campo :attribute debe ser aceptado cuando :other es :value.',
    'active_url' => 'El campo :attribute debe ser una URL válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => 'El campo :attribute solo puede contener letras.',
    'alpha_dash' => 'El campo :attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute solo puede contener letras y números.',
    'any_of' => 'El campo :attribute no es válido.',
    'array' => 'El campo :attribute debe ser una lista.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',

    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El archivo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],

    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_format' => 'El campo :attribute no coincide con el formato :format.',
    'different' => 'El campo :attribute y :other deben ser diferentes.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',

    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'exists' => 'El :attribute seleccionado no es válido.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'filled' => 'El campo :attribute debe contener un valor.',

    'image' => 'El campo :attribute debe ser una imagen.',

    'in' => 'El :attribute seleccionado no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'json' => 'El campo :attribute debe contener un JSON válido.',

    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El archivo :attribute no puede superar los :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],

    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El archivo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser como mínimo :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],

    'not_in' => 'El :attribute seleccionado no es válido.',

    'numeric' => 'El campo :attribute debe ser un número.',

    'password' => [
        'letters' => 'El campo :attribute debe contener al menos una letra.',
        'mixed' => 'El campo :attribute debe contener mayúsculas y minúsculas.',
        'numbers' => 'El campo :attribute debe contener al menos un número.',
        'symbols' => 'El campo :attribute debe contener al menos un símbolo.',
        'uncompromised' => 'El :attribute indicado apareció en una filtración de datos.',
    ],

    'regex' => 'El formato del campo :attribute no es válido.',

    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_unless' => 'El campo :attribute es obligatorio salvo que :other sea :value.',

    'same' => 'El campo :attribute debe coincidir con :other.',

    'size' => [
        'array' => 'El campo :attribute debe contener :size elementos.',
        'file' => 'El archivo :attribute debe tener :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],

    'string' => 'El campo :attribute debe ser texto.',
    'timezone' => 'El campo :attribute debe ser una zona horaria válida.',

    'unique' => 'El :attribute ya está registrado.',
    'uploaded' => 'El :attribute no se pudo subir.',

    // Faltaban: sin ellos se mostraba la clave cruda (p. ej. "validation.mimetypes").
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'extensions' => 'El campo :attribute debe tener una de estas extensiones: :values.',
    'dimensions' => 'La imagen :attribute tiene dimensiones no válidas.',
    'decimal' => 'El campo :attribute debe tener :decimal decimales.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'enum' => 'El valor elegido en :attribute no es válido.',
    'gt' => [
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
        'array' => 'El campo :attribute debe tener más de :value elementos.',
        'file' => 'El archivo :attribute debe pesar más de :value kilobytes.',
    ],
    'gte' => [
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string' => 'El campo :attribute debe tener al menos :value caracteres.',
        'array' => 'El campo :attribute debe tener :value elementos o más.',
        'file' => 'El archivo :attribute debe pesar al menos :value kilobytes.',
    ],
    'lt' => [
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
        'file' => 'El archivo :attribute debe pesar menos de :value kilobytes.',
    ],
    'lte' => [
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string' => 'El campo :attribute debe tener como máximo :value caracteres.',
        'array' => 'El campo :attribute no debe tener más de :value elementos.',
        'file' => 'El archivo :attribute debe pesar como máximo :value kilobytes.',
    ],
    'max_digits' => 'El campo :attribute no debe tener más de :max dígitos.',
    'min_digits' => 'El campo :attribute debe tener al menos :min dígitos.',
    'multiple_of' => 'El campo :attribute debe ser múltiplo de :value.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no está presente.',
    'starts_with' => 'El campo :attribute debe empezar con: :values.',
    'ends_with' => 'El campo :attribute debe terminar con: :values.',
    'prohibited' => 'El campo :attribute no está permitido.',
    'present' => 'El campo :attribute debe estar presente.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    'custom' => [],

    'attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'phone' => 'teléfono',
        'address' => 'dirección',
        'client_id' => 'cliente',
        'building_id' => 'edificio',
        'user_id' => 'usuario',
        'description' => 'descripción',
        'notes' => 'observaciones',
        'status' => 'estado',
        'visit_date' => 'fecha de visita',
    ],

];
