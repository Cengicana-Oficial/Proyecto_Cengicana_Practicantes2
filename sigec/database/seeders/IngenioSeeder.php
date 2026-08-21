<?php

namespace Database\Seeders;

use App\Models\Ingenio;
use Illuminate\Database\Seeder;

class IngenioSeeder extends Seeder
{
    /**
     * Portado de la constante INGENIOS del prototipo SIGEC_v12.html.
     */
    public function run()
    {
        $nombres = [
            'Ingenio Palo Gordo',
            'Ingenio Pantaleón',
            'Ingenio Magdalena',
            'Ingenio La Unión',
            'Ingenio Madre Tierra',
        ];

        foreach ($nombres as $nombre) {
            Ingenio::firstOrCreate(['nombre' => $nombre]);
        }
    }
}
