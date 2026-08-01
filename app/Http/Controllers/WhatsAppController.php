<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    public function connect(Request $request)
    {
        $company = Company::where('slug', $request->route('company'))->firstOrFail();

        $appId = config('services.facebook.client_id');

        $redirectUri = route('whatsapp.callback');

        $state = encrypt([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
        ]);

        $url = 'https://www.facebook.com/v26.0/dialog/oauth?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', [
                'business_management',
                'whatsapp_business_management',
                'whatsapp_business_messaging',
            ]),
        ]);

        return redirect($url);
    }

    public function callback(Request $request)
    {
        $state = decrypt($request->input('state'));

        $company = \App\Models\Company::findOrFail($state['company_id']);

        $response = Http::asForm()->post('https://graph.facebook.com/v26.0/oauth/access_token', [
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri' => route('whatsapp.callback'),
            'code' => $request->input('code'),
        ]);

        if (! $response->successful()) {
            abort(400, 'No se pudo obtener el token de acceso');
        }

        $token = $response->json('access_token');

        $businessesResponse = Http::withToken($token)
            ->get('https://graph.facebook.com/v26.0/me/businesses');

        if (! $businessesResponse->successful()) {
            abort(400, 'No se pudieron obtener los negocios de Meta');
        }

        $businesses = $businessesResponse->json('data', []);

        \Log::info('WhatsApp negocios disponibles', [
            'company_id' => $company->id,
            'businesses' => $businesses,
        ]);

        $business = collect($businesses)->first();

        if (! $business) {
            abort(400, 'No se encontró ningún Business Manager');
        }

        $businessId = $business['id'];

        $wabaResponse = Http::withToken($token)
            ->get("https://graph.facebook.com/v26.0/{$businessId}/owned_whatsapp_business_accounts");

        if (! $wabaResponse->successful()) {
            abort(400, 'No se pudieron obtener las cuentas WhatsApp Business');
        }

        $wabas = $wabaResponse->json('data', []);

        \Log::info('WhatsApp WABAs disponibles', [
            'business_id' => $businessId,
            'wabas' => $wabas,
        ]);

        $waba = collect($wabas)->first();

        if (! $waba) {
            abort(400, 'No se encontró WABA');
        }

        $wabaId = $waba['id'];

        $phonesResponse = Http::withToken($token)
            ->get("https://graph.facebook.com/v26.0/{$wabaId}/phone_numbers");

        if (! $phonesResponse->successful()) {
            abort(400, 'No se pudieron obtener los números WhatsApp');
        }

        $phones = $phonesResponse->json('data', []);

        \Log::info('WhatsApp números disponibles', [
            'waba_id' => $wabaId,
            'phones' => $phones,
        ]);

        $phone = collect($phones)->first();

        if (! $phone) {
            abort(400, 'No se encontró Phone Number ID');
        }

        $company->update([
            'whatsapp_access_token' => $token,
            'whatsapp_business_id' => $businessId,
            'whatsapp_waba_id' => $wabaId,
            'whatsapp_phone_number_id' => $phone['id'],
            'whatsapp_connected' => true,
        ]);

        \Log::info('WhatsApp conectado correctamente', [
            'company_id' => $company->id,
            'business_id' => $businessId,
            'waba_id' => $wabaId,
            'phone_number_id' => $phone['id'],
        ]);

        return redirect('/')->with('success', 'WhatsApp Business conectado correctamente');
    }
}
