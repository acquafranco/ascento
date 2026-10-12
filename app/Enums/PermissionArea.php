<?php

namespace App\Enums;

/**
 * Áreas del panel que el dueño puede habilitar a un usuario administrativo
 * (Etapa 6), cada una con «ver» y, si corresponde, «gestionar» (gestionar
 * incluye ver). El dueño y el SuperAdmin no dependen de esto: lo tienen todo.
 * Lo reservado al dueño (suscripción, plan, «Mi empresa», usuarios y
 * permisos, exportación completa) no es un área: nunca se habilita.
 */
enum PermissionArea: string
{
    case Clients = 'clients';
    case Buildings = 'buildings';
    case Technicians = 'technicians';
    case Visits = 'visits';
    case WorkOrders = 'work_orders';
    case Inspections = 'inspections';
    case Quotes = 'quotes';
    case Documents = 'documents';
    case Indicators = 'indicators';
    case Attention = 'attention';
    case Receivables = 'receivables';
    case Stock = 'stock';
    case Tasks = 'tasks';
    case Contacts = 'contacts';

    public const VIEW = 'view';

    public const MANAGE = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::Clients => 'Clientes',
            self::Buildings => 'Edificios y ascensores',
            self::Technicians => 'Técnicos',
            self::Visits => 'Visitas y agenda',
            self::WorkOrders => 'Órdenes de trabajo',
            self::Inspections => 'Inspecciones y reportes',
            self::Quotes => 'Presupuestos',
            self::Documents => 'Documentos y remitos',
            self::Indicators => 'Indicadores',
            self::Attention => 'Centro de atención',
            self::Receivables => 'Cobranzas',
            self::Stock => 'Stock',
            self::Tasks => 'Tareas y recordatorios',
            self::Contacts => 'Historial de contactos',
        };
    }

    /** Área de un tipo de trabajo (fotos, visitas): mantenimiento, inspección u orden. */
    public static function forJobType(?string $type): self
    {
        return match ($type) {
            'inspection' => self::Inspections,
            'work_order' => self::WorkOrders,
            default => self::Visits,
        };
    }

    /** Indicadores y Centro de atención son solo de consulta. */
    public function hasManage(): bool
    {
        return ! in_array($this, [self::Indicators, self::Attention], true);
    }

    /** Clave guardada en users.permissions: «clients.view», «clients.manage»… */
    public function key(string $level): string
    {
        return $this->value.'.'.$level;
    }

    /** @return list<string> todas las claves válidas */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::cases() as $area) {
            $keys[] = $area->key(self::VIEW);

            if ($area->hasManage()) {
                $keys[] = $area->key(self::MANAGE);
            }
        }

        return $keys;
    }
}
