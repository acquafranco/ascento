{{--
    409: el trabajo cambió mientras se completaba (por ejemplo, la orden ya tiene
    remito o ya no está en curso). Se muestra el motivo, que siempre es un texto
    propio de Ascento (abort(409, '…')), sin datos técnicos.
--}}
@extends('errors.minimal')

@section('title', 'Este trabajo ya cambió')
@section('code', '409')
@section('message', ($exception ?? null)?->getMessage() ?: 'Este trabajo cambió mientras lo completabas. Volvé a la lista de trabajos para ver su estado.')
