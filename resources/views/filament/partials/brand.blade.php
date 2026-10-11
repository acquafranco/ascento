{{-- Marca del panel: logo de la empresa si lo cargó; si no, el de Ascento. --}}
@if($logo)
    <img src="{{ $logo }}" alt="{{ $name }}" style="height: 2.5rem; width: auto; max-width: 11rem; object-fit: contain;">
@else
    <span style="display: inline-flex; align-items: center; gap: .6rem;">
        <img src="{{ asset('images/brand/logo-64.png') }}" alt="" width="32" height="32" style="width: 32px; height: 32px; border-radius: 7px;">
        <span style="font-weight: 700; font-size: 1.15rem; letter-spacing: -0.01em;">Ascento</span>
    </span>
@endif
