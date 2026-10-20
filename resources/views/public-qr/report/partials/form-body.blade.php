<p class="qr-step">Paso 1 de 2</p>
<h2 style="margin:0 0 .3rem; font-size:1.3rem">Reportar un problema</h2>
<p class="qr-hint" style="margin:0 0 1rem; font-size:.92rem">Contanos qué notaste. No hace falta cuenta ni datos personales. El edificio ya está identificado por el código QR.</p>

@if($errors->any())
    <div class="qr-errors" role="alert" style="margin-bottom:1rem">Revisá lo que falta: {{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('public-qr.report.review', ['token' => $page->token]) }}" class="qr-form" novalidate>
    <input type="hidden" name="k" value="{{ $nonce }}">

    <div class="qr-field">
        <fieldset>
            <legend>¿Qué notaste?</legend>
            <div class="qr-opts">
                @foreach($categories as $key => $label)
                    <label class="qr-opt"><input type="radio" name="category" value="{{ $key }}" @checked(($old['category'] ?? null) === $key) required><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </fieldset>
        @if($errors->has('category'))<p class="qr-error">{{ $errors->first('category') }}</p>@endif
    </div>

    @if($elevators->count() > 1)
        <div class="qr-field">
            <label for="qr-elevator">¿En qué ascensor? (opcional)</label>
            <select id="qr-elevator" name="elevator_id" class="qr-input">
                <option value="">No sé / no importa</option>
                @foreach($elevators as $e)
                    <option value="{{ $e->id }}" @selected((string) ($old['elevator_id'] ?? '') === (string) $e->id)>{{ $e->label }}</option>
                @endforeach
            </select>
            @if($errors->has('elevator_id'))<p class="qr-error">{{ $errors->first('elevator_id') }}</p>@endif
        </div>
    @elseif($elevators->count() === 1)
        <input type="hidden" name="elevator_id" value="{{ $elevators->first()->id }}">
    @endif

    <div class="qr-field">
        <label for="qr-description">Contalo en pocas palabras</label>
        <textarea id="qr-description" name="description" class="qr-input" maxlength="1000" required placeholder="Por ejemplo: la puerta del 3.º piso tarda en cerrar desde ayer.">{{ $old['description'] ?? '' }}</textarea>
        <p class="qr-hint">Describí lo que viste o escuchaste. No hace falta saber qué falla.</p>
        @if($errors->has('description'))<p class="qr-error">{{ $errors->first('description') }}</p>@endif
    </div>

    <button type="submit" class="qr-cta">Revisar el reporte</button>
    <a href="{{ $page->publicUrl() }}" class="qr-btn">Volver a la información del ascensor</a>
</form>

@include('public-qr.report.partials.safety')
