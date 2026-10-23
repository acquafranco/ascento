<details class="mk-card">
    <summary style="cursor:pointer; font-weight:600; font-size:.875rem">Reportar este perfil a Ascento</summary>
    <div class="mk-filters" style="margin-top:.5rem">
        <div><label for="sv-rr">Motivo</label><select id="sv-rr" class="mk-input" wire:model="reportReason"><option value="">Elegí…</option>@foreach($reasons as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>@error('reportReason')<p class="mk-err">{{ $message }}</p>@enderror</div>
        <div style="grid-column:1 / -1"><label for="sv-rd">Detalle (opcional)</label><textarea id="sv-rd" class="mk-input" rows="2" wire:model="reportDetails" maxlength="2000"></textarea></div>
    </div>
    @error('reporte')<p class="mk-err">{{ $message }}</p>@enderror
    <div class="mk-actions"><button type="button" class="mk-btn danger" wire:click="report" data-sv-report>Enviar reporte</button></div>
</details>
