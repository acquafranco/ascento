<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pedido de implementación o de propuesta personalizada desde la calculadora. */
class SalesLead extends Model
{
    public const TYPES = ['implementation' => 'Servicio de implementación', 'custom' => 'Propuesta personalizada'];

    public const STATUSES = ['new' => 'Nuevo', 'contacted' => 'Contactado', 'closed' => 'Cerrado'];

    // Solo lo crea SalesCalculatorController, con una lista explícita de campos.
    protected $guarded = ['id'];

    protected $casts = ['inputs' => 'array', 'quote' => 'array'];
}
