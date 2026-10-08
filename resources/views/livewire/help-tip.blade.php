<div>
    @if($visible)
        @include('filament.partials.insights-styles')
        <div class="asc-tip" role="note">
            <span class="asc-tip-icon">💡</span>
            <div style="flex: 1">
                <p class="asc-tip-title">{{ $topic['title'] }}</p>
                <p class="asc-tip-body">{{ $topic['body'] }}</p>
            </div>
            <button type="button" wire:click="dismiss" class="asc-btn">Entendido</button>
        </div>
    @endif
</div>
