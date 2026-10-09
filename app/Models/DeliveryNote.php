<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeliveryNote extends Model
{
    use \App\Models\Concerns\SharesWithClient;


    use HasFactory;
    use BelongsToCompany;

        protected $fillable = [

        'number',
        'company_id',
        'building_id',
        'building_visit_id',
        'work_order_id',
        'assignment_type',
        'user_id',

        'description',
        'elevator_quantity',

        'freight_elevator_quantity',
        'performed',

        'month',
        'year',

        'signature_name',
        'signature',
        'client_signature',
        'client_signature_name',
    ];

    protected $casts = [

        'performed' => 'boolean',

        'month' => 'integer',

        'year' => 'integer',

        'elevator_quantity' => 'integer',

        'freight_elevator_quantity' => 'integer',

    ];


    protected static function booted(): void
    {
        // Un remito es un documento firmado: no se borra nunca desde la
        // aplicación (ni por UI ni por código). Para anularlo habría que
        // implementar un estado "anulado", no eliminarlo.
        static::deleting(fn () => false);

        static::creating(function ($deliveryNote) {

        if (!$deliveryNote->public_token) {

            $deliveryNote->public_token = Str::uuid();

        }

            $lastNumber = static::where(
                'company_id',
                $deliveryNote->company_id
            )->max('number');

            $nextNumber = $lastNumber
                ? ((int) $lastNumber) + 1
                : 1;

            $deliveryNote->number = str_pad(
                $nextNumber,
                8,
                '0',
                STR_PAD_LEFT
            );
        });
    }

    /**
     * Firma lista para usar como src de <img>: solo data URLs de imagen.
     * Los remitos anteriores a la validación pueden tener cualquier
     * texto guardado (URLs externas, javascript:, etc.).
     */
    public function safeSignature(string $attribute = 'signature'): ?string
    {
        $value = $this->{$attribute};

        return is_string($value) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $value)
            ? $value
            : null;
    }

    public function getRouteKeyName()
    {
        return 'number';
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
    public function buildingVisit()
    {
        return $this->belongsTo(
            BuildingVisit::class,
            'building_visit_id'
        );
    }

    public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

}
