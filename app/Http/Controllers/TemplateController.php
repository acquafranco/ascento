<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Users\Pages\TechnicianSheetPage;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\Technicians\TechnicianSheet;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Planilla del técnico en la app («Planilla»): solo la propia. La de otro
 * técnico la ve el dueño de la empresa en el panel (TechnicianSheetPage);
 * las direcciones viejas del admin redirigen ahí. Toda la regla de qué se ve
 * y quién lo ve está en TechnicianSheet.
 */
class TemplateController extends Controller
{
    public function __construct(private TechnicianSheet $sheets) {}

    public function index(): View
    {
        $user = auth()->user();
        abort_unless(TechnicianSheet::canView($user, $user), 404);

        [$month, $year] = $this->period();

        return view('templates.index', [
            'sheet' => $this->sheets->month($user, $month, $year),
            'remitoUrl' => fn (DeliveryNote $note) => $this->remitoUrl($note, $user),
        ]);
    }

    public function day($company, string $date): View
    {
        $user = auth()->user();
        abort_unless(TechnicianSheet::canView($user, $user), 404);

        $date = $this->parseDate($date);

        return view('templates.day', [
            'jobs' => $this->sheets->day($user, $date),
            'date' => $date,
            'remitoUrl' => fn (DeliveryNote $note) => $this->remitoUrl($note, $user),
        ]);
    }

    /** Dirección vieja de la planilla de un técnico (admin): ahora está en el panel. */
    public function userTemplate($company, User $user): RedirectResponse
    {
        abort_unless(TechnicianSheet::canView(auth()->user(), $user), 404);
        [$month, $year] = $this->period();

        return redirect()->to(TechnicianSheetPage::getUrl(['record' => $user, 'month' => $month, 'year' => $year]));
    }

    public function userTemplateDay($company, User $user, string $date): RedirectResponse
    {
        abort_unless(TechnicianSheet::canView(auth()->user(), $user), 404);
        $date = $this->parseDate($date);

        return redirect()->to(TechnicianSheetPage::getUrl(['record' => $user, 'month' => $date->month, 'year' => $date->year]));
    }

    /**
     * El remito del trabajo: el propio, en la app; el de un compañero (un
     * trabajo en el que participó), por su enlace público, como antes.
     */
    private function remitoUrl(DeliveryNote $note, User $user): ?string
    {
        $slug = $user->company?->slug;

        if ((int) $note->user_id === (int) $user->id) {
            return route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $note->number]);
        }

        return $note->public_token ? route('delivery-notes.public', ['company' => $slug, 'token' => $note->public_token]) : null;
    }

    /**
     * Mes/año pedidos por query string, validados (antes un valor no
     * numérico terminaba en un 500 al armar las semanas).
     *
     * @return array{0: int, 1: int}
     */
    private function period(): array
    {
        $data = validator(request()->only('month', 'year'), [
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2000,2100',
        ])->validate();

        return [
            (int) ($data['month'] ?? now()->month),
            (int) ($data['year'] ?? now()->year),
        ];
    }

    private function parseDate(string $date): Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            abort(404);
        }

        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 404);

        return $parsed;
    }
}
