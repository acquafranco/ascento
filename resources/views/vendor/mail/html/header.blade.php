@props(['url'])
{{-- Encabezado de Ascento: logo + nombre sobre la franja oscura (no depende de APP_NAME). --}}
<tr>
<td class="header" style="background-color: #12151C; padding: 22px 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ asset('images/brand/logo-128.png') }}" width="40" height="40" alt="Ascento" style="width: 40px; height: 40px; border: 0; vertical-align: middle; border-radius: 9px;">
<span style="color: #F7F7F4; font-size: 20px; font-weight: 700; letter-spacing: -0.2px; vertical-align: middle; padding-left: 10px;">Ascento</span>
</a>
</td>
</tr>
