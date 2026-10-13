<div>
    @if($visible)
        @include('filament.partials.insights-styles')
        <div class="asc-tip" role="note">
            <span class="asc-tip-icon">💡</span>
            <div style="flex: 1">
                <p class="asc-tip-title">{{ $topic['title'] }}</p>
                <p class="asc-tip-body">{{ $topic['body'] }}</p>
                @if($guideUrl)<a href="{{ $guideUrl }}" class="asc-link" style="color:#d97706; font-size:.85rem" data-help-guide-link>Ver la guía completa</a>@endif
            </div>
            <button type="button" wire:click="dismiss" class="asc-btn">Entendido</button>
        </div>
    @endif
</div>
