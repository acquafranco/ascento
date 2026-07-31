<?php

namespace App\Support;

class CompanyContext
{
    public static function set($companyId): void
    {
        session([
            'selected_company_id' => $companyId,
        ]);
    }

    public static function get()
    {
        return session('selected_company_id');
    }

    public static function clear(): void
    {
        session()->forget('selected_company_id');
    }
}
