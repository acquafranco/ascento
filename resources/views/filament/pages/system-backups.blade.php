@php
    $pill = ['completed' => 'is-done', 'failed' => 'is-overdue', 'requested' => 'is-pending', 'running' => 'is-pending'];
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div @if($pending) wire:poll.10s @endif class="asc-stack">
        <div class="asc-card">
            <p style="margin: 0 0 .5rem; font-weight: 600">Qué incluye</p>
            <p class="asc-q">La base de datos completa (volcado consistente) y los archivos: fotos, videos, documentos y logos. Cada backup trae un manifiesto con las filas de cada tabla y controla que estén los archivos que la base menciona.</p>
            <p class="asc-c" style="margin-top: .5rem">
                Retención: {{ config('backup.keep_daily') }} diarios, {{ config('backup.keep_weekly') }} semanales y los manuales {{ config('backup.keep_manual_days') }} días. Nunca se borra el último completo.
                @unless($encrypted) <strong>Atención: sin BACKUP_ARCHIVE_PASSWORD los backups quedan sin cifrar.</strong> @endunless
                <strong>Se guardan en este mismo servidor:</strong> protegen de errores y despliegues fallidos, no de perder el servidor. Descargá una copia periódicamente o configurá una copia externa (ver docs/backups.md).
            </p>
            <div style="margin-top: .9rem">
                <x-filament::button wire:click="requestBackup" icon="heroicon-o-circle-stack" :disabled="$pending">
                    {{ $pending ? 'Generando backup…' : 'Crear backup ahora' }}
                </x-filament::button>
            </div>
        </div>

        <section>
            <h2 class="asc-h2">Historial</h2>
            @if($backups->isEmpty())
                <p class="asc-locked">Todavía no hay backups.</p>
            @else
                <div class="asc-card asc-scroll">
                    <table class="asc-table">
                        <thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Contenido</th><th>Tamaño</th><th></th></tr></thead>
                        <tbody>
                            @foreach($backups as $backup)
                                <tr>
                                    <td>{{ $backup->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $backup->type === 'manual' ? 'Manual · '.($backup->requester?->name ?? '—') : 'Automático' }}</td>
                                    <td>
                                        <span class="asc-pill {{ $pill[$backup->status] ?? '' }}">{{ \App\Models\Backup::STATUSES[$backup->status] ?? $backup->status }}</span>
                                        @if($backup->error)<div class="asc-small asc-muted">{{ $backup->error }}</div>@endif
                                        @if($backup->verified_at)<div class="asc-small asc-muted">Verificado {{ $backup->verified_at->format('d/m H:i') }}</div>@endif
                                    </td>
                                    <td>
                                        @if($backup->summary)
                                            {{ number_format($backup->summary['rows'], 0, ',', '.') }} filas · {{ number_format($backup->summary['files'], 0, ',', '.') }} archivos
                                            {{ $backup->encrypted ? '· cifrado' : '· sin cifrar' }}
                                            @if($backup->summary['missing_referenced_files'])<div class="asc-small asc-muted">{{ $backup->summary['missing_referenced_files'] }} archivos que la base menciona no estaban en el disco.</div>@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $backup->sizeLabel() }}</td>
                                    <td>
                                        @if($backup->isDownloadable())
                                            <a href="{{ route('backups.download', $backup) }}" style="font-weight: 600; color: var(--asc-accent, #C24800)">Descargar</a>
                                            · <button type="button" wire:click="verify({{ $backup->id }})" style="font-weight: 600; text-decoration: underline">Verificar</button>
                                            @if($backup->downloads->isNotEmpty())<div class="asc-small asc-muted">Descargado {{ $backup->downloads->count() }} {{ $backup->downloads->count() === 1 ? 'vez' : 'veces' }} · última: {{ $backup->downloads->last()->downloaded_at->format('d/m H:i') }} ({{ $backup->downloads->last()->user?->name }})</div>@endif
                                        @elseif($backup->file_deleted_at)
                                            <span class="asc-small asc-muted">Archivo borrado por retención</span>
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
