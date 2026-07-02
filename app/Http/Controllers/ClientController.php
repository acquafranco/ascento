<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
    public function index()
{
    return view('clients.index', [
        'clients' => Client::all()
    ]);
    }

    public function show(Client $client)
    {
        return view('clients.show', compact('client'));
}

}
