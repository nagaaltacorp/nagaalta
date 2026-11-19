<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Creditor;
use Illuminate\Http\Request;

class CreditorController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->customers($request),
        ]);
    }

    public function flutterIndex(Request $request)
    {
        if (!$request->user() && !$request->filled('branch_id') && !$request->filled('user_id')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'data' => $this->customers($request),
        ]);
    }

    private function customers(Request $request)
    {
        $query = Creditor::query()->orderBy('name');
        $keyword = trim((string) $request->query('q', ''));

        if ($keyword !== '') {
            $digits = preg_replace('/\D+/', '', $keyword) ?? '';
            $query->where(function ($match) use ($keyword, $digits) {
                $match->where('name', 'like', '%'.$keyword.'%');

                if ($digits !== '') {
                    $match->orWhere('phone_digits', 'like', '%'.$digits.'%');
                }
            });
        }

        return $query->limit(500)->get();
    }
}
