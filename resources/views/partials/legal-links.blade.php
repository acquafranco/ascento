{{-- Enlaces a las páginas legales (pies de página fuera de la portada). $style: estilo en línea opcional del contenedor. --}}
<nav aria-label="Documentos legales" data-legal-links style="display:flex; flex-wrap:wrap; justify-content:center; gap:.35rem 1rem; font-size:12px; {{ $style ?? '' }}">
    <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener" style="color:inherit">Términos</a>
    <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" style="color:inherit">Privacidad</a>
    <a href="{{ route('legal.cookies') }}" target="_blank" rel="noopener" style="color:inherit">Cookies</a>
    <a href="{{ route('legal.data-deletion') }}" target="_blank" rel="noopener" style="color:inherit">Eliminación de datos</a>
</nav>
