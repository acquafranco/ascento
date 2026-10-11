<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <meta name="robots" content="noindex, nofollow">
    <title>Enlace no disponible</title>
</head>
<body style="margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#F7F7F4;color:#1F2430;">
    <main style="max-width:520px;margin:12vh auto;padding:0 16px;">
        <div style="background:#fff;border:1px solid #E6E4DC;border-radius:16px;padding:28px;">
            <h1 style="margin:0 0 8px;font-size:22px;">Este enlace ya no está disponible</h1>
            <p style="margin:0;color:#6B7080;">Los enlaces a presupuestos vencen por seguridad, o el presupuesto fue reemplazado. Pedile a {{ $company->name }} que te lo envíe de nuevo.</p>
            @if($company->phone || $company->email)
                <p style="margin:16px 0 0;color:#3D4250;">
                    @if($company->phone)Tel. {{ $company->phone }}@endif
                    @if($company->phone && $company->email) · @endif
                    @if($company->email)<a href="mailto:{{ $company->email }}" style="color:#C24800;">{{ $company->email }}</a>@endif
                </p>
            @endif
        </div>
    </main>
</body>
</html>
