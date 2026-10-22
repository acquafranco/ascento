<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800" role="alert" data-calc-errors>
    <strong>Revisá los datos:</strong>
    <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
