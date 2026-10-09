@php
    $statuses = \App\Models\CompanyExport::STATUSES;
    $pill = ['completed' => 'is-done', 'failed' => 'is-overdue', 'requested' => 'is-pending', 'generating' => 'is-pending'];
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div @if($pending) wire:poll.5s @endif class="asc-stack">
        <div class="asc-card">
            <p style="margin: 0 0 .5rem; font-weight: 600">¿Qué se descarga?</p>
            <p class="asc-q">Un archivo ZIP con un Excel (una hoja por tema: clientes, edificios, ascensores, contratos, técnicos, mantenimientos, inspecciones, órdenes, reportes, remitos, presupuestos, stock, cobranzas…) y las fotos y documentos adjuntos.</p>
            <p class="asc-c" style="margin-top: .5rem">Es una copia de tus <strong>datos de negocio</strong> para que los tengas. No es un backup del servidor ni sirve para restaurar Ascento (eso lo hacemos nosotros). No incluye contraseñas, tokens ni datos de pago. El archivo queda disponible {{ \App\Models\CompanyExport::KEEP_DAYS }} días.</p>
            <div style="margin-top: .9rem">
                <x-filament::button wire:click="requestExport" icon="heroicon-o-arrow-down-tray" :disabled="$pending">
                    {{ $pending ? 'Preparando exportación…' : 'Generar exportación' }}
                </x-filament::button>
            </div>
        </div>

        <section>
            <h2 class="asc-h2">Historial</h2>
            @if($exports->isEmpty())
                <p class="asc-locked">Todavía no generaste ninguna exportación.</p>
            @else
                <div class="asc-card asc-scroll">
                    <table class="asc-table">
                        <thead><tr><th>Fecha</th><th>Pedida por</th><th>Estado</th><th>Contenido</th><th>Tamaño</th><th></th></tr></thead>
                        <tbody>
                            @foreach($exports as $export)
                                <tr>
                                    <td>{{ $export->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $export->requester?->name ?? '—' }}</td>
                                    <td>
                                        <span class="asc-pill {{ $pill[$export->status] ?? '' }}">{{ $statuses[$export->status] ?? $export->status }}</span>
                                        @if($export->error)<div class="asc-small asc-muted">{{ $export->error }}</div>@endif
                                        @if($export->warnings)<div class="asc-small asc-muted">{{ count($export->warnings) }} archivos no se pudieron incluir (ver hoja "Archivos").</div>@endif
                                    </td>
                                    <td>
                                        @if($export->categories)
                                            {{ count($export->categories) }} categorías · {{ number_format((int) $export->record_count, 0, ',', '.') }} registros
                                        @else — @endif
                                    </td>
                                    <td>{{ $export->sizeLabel() ?? '—' }}</td>
                                    <td>
                                        @if($export->isDownloadable())
                                            <a class="asc-link" style="font-weight: 600" href="{{ route('company-exports.download', $export) }}">Descargar</a>
                                            <div class="asc-small asc-muted">Vence {{ $export->expires_at?->format('d/m/Y') }}</div>
                                        @elseif($export->file_deleted_at)
                                            <span class="asc-small asc-muted">Vencida</span>
                                        @endif
                                        @if($export->downloads->isNotEmpty())
                                            <div class="asc-small asc-muted">Descargada {{ $export->downloads->count() }} {{ $export->downloads->count() === 1 ? 'vez' : 'veces' }} · última: {{ $export->downloads->first()->downloaded_at->format('d/m H:i') }} ({{ $export->downloads->first()->user?->name }})</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
