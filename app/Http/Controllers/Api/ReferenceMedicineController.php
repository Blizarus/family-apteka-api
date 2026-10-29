<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceMedicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceMedicineController extends Controller
{
    /**
     * Поиск по единому справочнику лекарственных препаратов.
     * Быстрый поиск по торговому наименованию, действующему веществу (МНН) или штрихкоду.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', $request->input('q', '')));

        $query = ReferenceMedicine::query();

        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($q) use ($term, $search) {
                $q->where('trade_name', 'like', $term)
                  ->orWhere('inn', 'like', $term)
                  ->orWhere('barcode', 'like', '%' . $search . '%')
                  ->orWhere('atc_code', 'like', '%' . $search . '%');
            });
        }

        // Возвращаем только активные препараты справочника
        $medicines = $query->where('status', 'active')
            ->orderBy('trade_name')
            ->limit(50)
            ->get();

        return response()->json([
            'query' => $search,
            'count' => $medicines->count(),
            'data' => $medicines,
        ], 200);
    }

    /**
     * Детальная карточка препарата из справочника.
     */
    public function show(ReferenceMedicine $referenceMedicine): JsonResponse
    {
        return response()->json([
            'data' => $referenceMedicine,
        ], 200);
    }
}
