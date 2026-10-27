<?php

namespace Database\Seeders;

use App\Models\Ensayo;
use App\Models\Ingenio;
use App\Models\User;
use Illuminate\Database\Seeder;

class EnsayoSeeder extends Seeder
{
    /**
     * Portado de la constante ENSAYOS del prototipo SIGEC_v12.html.
     */
    protected array $ensayos = [
        ['id' => 1, 'codigo' => 'E-AG001-01', 'proyecto_id' => 1, 'ingenio' => 'Ingenio Palo Gordo', 'finca' => 'Finca San Diego', 'lote' => 'Lote 12', 'diseno' => 'Bloques al azar', 'cultivo' => 'Caña de azúcar', 'variedad' => 'CG98-78', 'responsable' => 'Ing. Juan Pérez', 'estado' => 'En campo', 'lat' => 14.5892, 'lng' => -91.5143, 'num_tratamientos' => 3, 'num_repeticiones' => 3, 'area_parcela' => 50],
        ['id' => 2, 'codigo' => 'E-AG001-02', 'proyecto_id' => 1, 'ingenio' => 'Ingenio Magdalena', 'finca' => 'Finca El Carmen', 'lote' => 'Lote 4', 'diseno' => 'Parcelas divididas', 'cultivo' => 'Caña de azúcar', 'variedad' => 'CG02-163', 'responsable' => 'Ing. Juan Pérez', 'estado' => 'En campo', 'lat' => 14.3601, 'lng' => -90.9812, 'num_tratamientos' => 2, 'num_repeticiones' => 3, 'area_parcela' => 40],
        ['id' => 3, 'codigo' => 'E-AG004-01', 'proyecto_id' => 2, 'ingenio' => 'Ingenio Pantaleón', 'finca' => 'Finca Las Brisas', 'lote' => 'Lote 7', 'diseno' => 'Bloques al azar', 'cultivo' => 'Caña de azúcar', 'variedad' => 'CG98-46', 'responsable' => 'Ing. Carlos Mejía', 'estado' => 'Planificado', 'lat' => 14.4210, 'lng' => -91.0934, 'num_tratamientos' => 4, 'num_repeticiones' => 4, 'area_parcela' => 60],
        ['id' => 4, 'codigo' => 'E-AG010-01', 'proyecto_id' => 3, 'ingenio' => 'Ingenio La Unión', 'finca' => 'Finca Vista Hermosa', 'lote' => 'Lote 1', 'diseno' => 'Cuadro latino', 'cultivo' => 'Caña de azúcar', 'variedad' => 'Mixta (6 var.)', 'responsable' => 'Ing. Juan Pérez', 'estado' => 'Finalizado', 'lat' => 14.2798, 'lng' => -90.8761, 'num_tratamientos' => 6, 'num_repeticiones' => 1, 'area_parcela' => 80],
        ['id' => 5, 'codigo' => 'E-FT002-01', 'proyecto_id' => 4, 'ingenio' => 'Ingenio Madre Tierra', 'finca' => 'Finca Esperanza', 'lote' => 'Lote 9', 'diseno' => 'Bloques al azar', 'cultivo' => 'Caña de azúcar', 'variedad' => 'CGMex09-431', 'responsable' => 'María López', 'estado' => 'En campo', 'lat' => 14.1925, 'lng' => -90.7340, 'num_tratamientos' => 3, 'num_repeticiones' => 4, 'area_parcela' => 45],
        ['id' => 6, 'codigo' => 'E-RG003-01', 'proyecto_id' => 5, 'ingenio' => 'Ingenio Palo Gordo', 'finca' => 'Finca San Diego', 'lote' => 'Lote 20', 'diseno' => 'Parcelas divididas', 'cultivo' => 'Caña de azúcar', 'variedad' => 'CG98-78', 'responsable' => 'María López', 'estado' => 'En campo', 'lat' => 14.5920, 'lng' => -91.5170, 'num_tratamientos' => 3, 'num_repeticiones' => 3, 'area_parcela' => 55],
    ];

    public function run()
    {
        foreach ($this->ensayos as $datos) {
            Ensayo::updateOrCreate(['id' => $datos['id']], [
                'codigo' => $datos['codigo'],
                'proyecto_id' => $datos['proyecto_id'],
                'ingenio_id' => Ingenio::where('nombre', $datos['ingenio'])->value('id'),
                'finca' => $datos['finca'],
                'lote' => $datos['lote'],
                'diseno' => $datos['diseno'],
                'cultivo' => $datos['cultivo'],
                'variedad' => $datos['variedad'],
                'responsable_id' => User::where('name', $datos['responsable'])->value('id'),
                'estado' => $datos['estado'],
                'lat' => $datos['lat'],
                'lng' => $datos['lng'],
                'num_tratamientos' => $datos['num_tratamientos'],
                'num_repeticiones' => $datos['num_repeticiones'],
                'area_parcela' => $datos['area_parcela'],
            ]);
        }
    }
}
