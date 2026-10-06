<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
    public function index()
{
    return view('clients.index', [
        'clients' => Client::orderBy('name')->get()
    ]);
    }

    public function show($company, Client $client)
    {
        // El binding ya está acotado a la empresa de la URL (scopeBindings
        // + scope global), así que un cliente de otra empresa da 404.
        $client->load('buildings');

        return view('clients.show', compact('client'));
    }

}
