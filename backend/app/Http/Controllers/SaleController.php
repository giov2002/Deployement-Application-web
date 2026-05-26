<?php
// app/Http/Controllers/SaleController.php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\OrderLine;
use App\Models\CashRegisterSession;
use App\Models\PointOfSale;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Services\SaleService;
use App\Services\PrintGroupingService;

class SaleController extends Controller
{
 protected SaleService $saleService;
    protected ?PrintGroupingService $printGroupingService = null;

    public function __construct(SaleService $saleService, ?PrintGroupingService $printGroupingService = null)
    {
        $this->saleService = $saleService;
        $this->printGroupingService = $printGroupingService;
    }

/**
 * POST /api/sales/{saleId}/validate
 */
public function validatePendingOrder(Request $request, $saleId)
{
    try {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'amount_received' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
        ]);

        $sale = Sale::findOrFail($saleId);
        $validatedSale = $this->saleService->validatePendingOrder($sale, $validated);
        
        // Récupérer les données formatées
        $formattedData = $this->saleService->getFormattedSaleData($validatedSale);
        
        // Ajouter le regroupement par imprimante (si le service existe)
        $printGroups = [];
        if (isset($this->printGroupingService)) {
            $printGroups = $this->printGroupingService->preparePrintData($validatedSale);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Commande validée avec succès',
            'data' => $formattedData,
            'print_groups' => $printGroups
        ], 200);
        
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Commande non trouvée.'], 404);
    } catch (ValidationException $e) {
        return response()->json(['error' => 'Erreur de validation', 'details' => $e->errors()], 422);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
    public function index(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('view.sales', 'api')) {
                return response()->json(['message' => 'Vous n\'avez pas la permission de voir les ventes.'], 403);
            }

            $sessionId = $request->query('cash_register_session_id');
            $sales = Sale::with(['user', 'orderLines.product', 'payments.payment']);

            if ($sessionId) {
                $sales->where('cash_register_session_id', $sessionId);
            }

            if ($request->filled('user_id')) {
                $sales->where('user_id', $request->query('user_id'));
            }

            $isAdmin = $user->hasRole('admin', 'api');
            $isManager = $user->hasAnyRole(['gerant', 'gérant'], 'api');

            if (!$isAdmin) {
                if ($isManager) {
                    $pointOfSaleId = $user->point_of_sale_id;
                    if ($pointOfSaleId) {
                        $sales->where('point_of_sale_id', $pointOfSaleId);
                    } else {
                        $sales->where('user_id', $user->id);
                    }
                } else {
                    $sales->where('user_id', $user->id);
                }
            }

            return response()->json($sales->orderByDesc('created_at')->get());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erreur lors de la récupération des ventes.'], 500);
        }
    }

    /**
     * GET /api/point-of-sales/{pointOfSale}/kpis
     */
    public function productKpis(PointOfSale $pointOfSale)
    {
        $user = auth()->user();

        if (!$user->hasPermissionTo('view.sales', 'api')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission de voir les KPIs.'], 403);
        }

        if ($user->hasRole('gerant', 'api')) {
            if ($user->point_of_sale_id !== $pointOfSale->id) {
                return response()->json(['message' => 'Vous n\'avez pas accès aux KPIs de ce point de vente.'], 403);
            }
        }

        $kpis = OrderLine::whereHas('sale', function ($query) use ($pointOfSale) {
            $query->where('point_of_sale_id', $pointOfSale->id)
                ->where('status', 'completed');
        })
            ->with('product')
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(total) as total_revenue')
            )
            ->groupBy('product_id')
            ->get()
            ->map(function ($orderLine) {
                return [
                    'name' => $orderLine->product->name,
                    'total_quantity' => $orderLine->total_quantity,
                    'total_revenue' => $orderLine->total_revenue,
                ];
            });

        return response()->json($kpis);
    }

    /**
     * GET /api/sales/monthly/{pointOfSaleId}
     */
    public function monthlySales(Request $request, $pointOfSaleId)
    {
        $user = auth()->guard('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        if (!$user->hasPermissionTo('view.sales', 'api')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission de voir les statistiques.'], 403);
        }

        $isAdmin = $user->hasRole('admin', 'api');
        $isManager = $user->hasAnyRole(['gerant', 'gérant'], 'api');

        if (!$isAdmin) {
            if ($isManager) {
                $managerPointOfSaleId = $user->point_of_sale_id;
                if ($managerPointOfSaleId && (int) $managerPointOfSaleId !== (int) $pointOfSaleId) {
                    return response()->json(['message' => 'Vous ne pouvez consulter que les statistiques de votre point de vente.'], 403);
                }
            } else {
                return response()->json(['message' => 'Seuls les administrateurs ou gérants peuvent consulter ces statistiques.'], 403);
            }
        }

        $year = (int) $request->query('year', now()->year);
        $startDateInput = $request->query('start_date');
        $endDateInput = $request->query('end_date');

        try {
            $startDate = $startDateInput ? Carbon::parse($startDateInput)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Le format de la date de début est invalide.'], 422);
        }

        try {
            $endDate = $endDateInput ? Carbon::parse($endDateInput)->endOfDay() : Carbon::create($year, 12, 31)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Le format de la date de fin est invalide.'], 422);
        }

        if ($endDate->lt($startDate)) {
            return response()->json(['message' => 'La date de fin doit être postérieure ou égale à la date de début.'], 422);
        }

        $saleTable = (new Sale())->getTable();
        $hasStatusColumn = Schema::hasColumn($saleTable, 'status');
        $hasPaymentColumn = Schema::hasColumn($saleTable, 'payment_id');

        $statusesInput = Arr::wrap($request->query('statuses', $request->query('status', [])));
        $statusesFilter = collect($statusesInput)->flatMap(function ($value) {
            if (is_array($value))
                return $value;
            if (is_string($value))
                return preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
            return [$value];
        })->map(fn($value) => trim((string) $value))->filter()->unique()->values();

        $paymentInput = Arr::wrap($request->query('payment_ids', $request->query('payment_id', [])));
        $paymentFilter = collect($paymentInput)->flatMap(function ($value) {
            if (is_array($value))
                return $value;
            if (is_string($value))
                return preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
            return [$value];
        })->map(fn($value) => (int) $value)->filter(fn($value) => $value > 0)->unique()->values();

        $salesQuery = Sale::query()
            ->where("{$saleTable}.point_of_sale_id", $pointOfSaleId)
            ->whereBetween("{$saleTable}.created_at", [$startDate, $endDate]);

        if ($hasStatusColumn) {
            if ($statusesFilter->isNotEmpty()) {
                $salesQuery->whereIn('status', $statusesFilter->all());
            } else {
                $salesQuery->where(function ($inner) {
                    $inner->whereNull('status')->orWhereNotIn('status', ['cancelled', 'canceled']);
                });
            }
        }

        if ($paymentFilter->isNotEmpty() && $hasPaymentColumn) {
            $salesQuery->whereIn('payment_id', $paymentFilter->all());
        }

        Carbon::setLocale(app()->getLocale() ?? 'fr');
        $cashRegex = "(cash|esp|espe|espec|liquid|liquide|argent)";

        $monthlySelect = [
            DB::raw("DATE_FORMAT({$saleTable}.created_at, '%Y-%m') as period"),
            DB::raw('COUNT(*) as transactions'),
            DB::raw("SUM(COALESCE({$saleTable}.final_amount, {$saleTable}.total_amount, 0)) as total_sales"),
        ];

        if ($hasPaymentColumn) {
            $monthlySelect[] = DB::raw("SUM(CASE WHEN LOWER(COALESCE(payments.name, '')) REGEXP '{$cashRegex}' THEN 1 ELSE 0 END) as cash_transactions");
            $monthlySelect[] = DB::raw("SUM(CASE WHEN LOWER(COALESCE(payments.name, '')) REGEXP '{$cashRegex}' THEN COALESCE({$saleTable}.final_amount, {$saleTable}.total_amount, 0) ELSE 0 END) as cash_sales");
        } else {
            $monthlySelect[] = DB::raw('0 as cash_transactions');
            $monthlySelect[] = DB::raw('0 as cash_sales');
        }

        $monthlyBuilder = (clone $salesQuery)->select($monthlySelect);
        if ($hasPaymentColumn) {
            $monthlyBuilder->leftJoin('payments', "{$saleTable}.payment_id", '=', 'payments.id');
        }

        $monthlyQuery = $monthlyBuilder->groupBy(DB::raw("DATE_FORMAT({$saleTable}.created_at, '%Y-%m')"))->orderBy('period')->get();

        $dailySelect = [
            DB::raw("DATE_FORMAT({$saleTable}.created_at, '%Y-%m') as period"),
            DB::raw("DATE({$saleTable}.created_at) as day_date"),
            DB::raw('COUNT(*) as transactions'),
            DB::raw("SUM(COALESCE({$saleTable}.final_amount, {$saleTable}.total_amount, 0)) as total_sales"),
        ];

        if ($hasPaymentColumn) {
            $dailySelect[] = DB::raw("SUM(CASE WHEN LOWER(COALESCE(payments.name, '')) REGEXP '{$cashRegex}' THEN 1 ELSE 0 END) as cash_transactions");
            $dailySelect[] = DB::raw("SUM(CASE WHEN LOWER(COALESCE(payments.name, '')) REGEXP '{$cashRegex}' THEN COALESCE({$saleTable}.final_amount, {$saleTable}.total_amount, 0) ELSE 0 END) as cash_sales");
        } else {
            $dailySelect[] = DB::raw('0 as cash_transactions');
            $dailySelect[] = DB::raw('0 as cash_sales');
        }

        $dailyBuilder = (clone $salesQuery)->select($dailySelect);
        if ($hasPaymentColumn) {
            $dailyBuilder->leftJoin('payments', "{$saleTable}.payment_id", '=', 'payments.id');
        }

        $dailyData = $dailyBuilder->groupBy(DB::raw("DATE_FORMAT({$saleTable}.created_at, '%Y-%m')"), DB::raw("DATE({$saleTable}.created_at)"))
            ->orderBy('period')->orderBy('day_date')->get()
            ->map(function ($row) {
                $date = Carbon::parse($row->day_date);
                return [
                    'period' => $row->period,
                    'date' => $date->toDateString(),
                    'label' => $date->translatedFormat('d MMM Y'),
                    'day' => $date->format('Y-m-d'),
                    'transactions' => (int) ($row->transactions ?? 0),
                    'total_sales' => (int) ($row->total_sales ?? 0),
                    'cash_sales' => (int) ($row->cash_sales ?? 0),
                    'cash_transactions' => (int) ($row->cash_transactions ?? 0),
                ];
            });

        $monthlyData = $monthlyQuery->map(function ($row) use ($dailyData) {
            try {
                $date = Carbon::createFromFormat('Y-m', $row->period)->startOfMonth();
                $label = $date->translatedFormat('F Y');
            } catch (\Throwable $e) {
                $label = $row->period;
            }

            $days = $dailyData->where('period', $row->period)->values()->map(function ($day) {
                $parsed = Carbon::parse($day['date']);
                return [
                    'period' => $day['period'],
                    'date' => $parsed->toDateString(),
                    'label' => $parsed->translatedFormat('d MMMM Y'),
                    'day' => $parsed->format('Y-m-d'),
                    'transactions' => $day['transactions'],
                    'total_sales' => $day['total_sales'],
                ];
            });

            $totalSales = (int) ($row->total_sales ?? 0);
            $transactions = (int) ($row->transactions ?? 0);

            return [
                'period' => $row->period,
                'label' => Str::title($label),
                'total_sales' => $totalSales,
                'transactions' => $transactions,
                'average_ticket' => $transactions > 0 ? (int) round($totalSales / max($transactions, 1)) : 0,
                'cash_sales' => (int) ($row->cash_sales ?? 0),
                'cash_transactions' => (int) ($row->cash_transactions ?? 0),
                'daily_breakdown' => $days,
            ];
        })->values();

        $overallTotalSales = (int) $monthlyData->sum('total_sales');
        $overallTransactions = (int) $monthlyData->sum('transactions');

        return response()->json([
            'data' => $monthlyData,
            'meta' => [
                'year' => $year,
                'point_of_sale_id' => (int) $pointOfSaleId,
                'filters' => ['start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString()],
                'overall' => [
                    'total_sales' => $overallTotalSales,
                    'transactions' => $overallTransactions,
                    'average_ticket' => $overallTransactions > 0 ? (int) round($overallTotalSales / max($overallTransactions, 1)) : 0,
                    'cash_sales' => (int) $monthlyData->sum('cash_sales'),
                    'cash_transactions' => (int) $monthlyData->sum('cash_transactions'),
                ],
            ],
        ]);
    }

    /**
     * POST /api/sales
     * 
     * Crée une vente complète et retourne les données formatées par catégorie
     */
    public function store(Request $request)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        if (!$user->hasPermissionTo('create.sales', 'api')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission de créer une vente.'], 403);
        }

        try {
            $validated = $request->validate([
                'table_id' => 'required|exists:tables,id',
                'user_id' => 'required|exists:users,id',
                'point_of_sale_id' => 'required|exists:point_of_sales,id',
                'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
                'total_amount' => 'required|numeric|min:0',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'final_amount' => 'required|numeric|min:0',
                'amount_received' => 'required|numeric|min:0',
                'change_returned' => 'nullable|numeric|min:0',
                'payment_id' => 'required|exists:payments,id',
                'status' => 'required|in:pending,completed,cancelled',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.total' => 'required|numeric|min:0',
            ]);

            $isAdmin = $user->hasRole('admin', 'api');
            if (!$isAdmin) {
                if ((int) $user->point_of_sale_id !== (int) $validated['point_of_sale_id']) {
                    return response()->json(['message' => 'Vous ne pouvez créer des ventes que sur votre point de vente.'], 403);
                }

                $session = CashRegisterSession::find($validated['cash_register_session_id']);
                if ($session && $session->is_closed) {
                    return response()->json(['message' => 'La session de caisse est fermée. Vous ne pouvez pas créer de vente.'], 422);
                }
            }

            $sale = $this->saleService->createSale($validated, $user);

            // Récupérer les données formatées par catégorie
            $formattedData = $this->saleService->getFormattedSaleData($sale);

            return response()->json([
                'success' => true,
                'message' => 'Vente créée avec succès',
                'data' => $formattedData
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    /**
     * POST /api/sales/pending-orders
     */
    public function createPendingOrder(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        if (!$user->hasPermissionTo('create.sales', 'api')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission de créer une commande.'], 403);
        }

        try {
            $validated = $request->validate([
                'table_id' => 'required|exists:tables,id',
                'user_id' => 'required|exists:users,id',
                'point_of_sale_id' => 'required|exists:point_of_sales,id',
                'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'order_lines' => 'required|array|min:1',
                'order_lines.*.product_id' => 'required|exists:products,id',
                'order_lines.*.quantity' => 'required|integer|min:1',
                'order_lines.*.price' => 'required|numeric|min:0',
            ]);

            $sale = $this->saleService->createPendingOrder($validated, $user);
            return response()->json($sale, 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/sales/{id}
     */
    public function show($id)
    {
        try {
            if ($id === 'current-session') {
                return response()->json(['error' => 'Invalid sale ID.'], 400);
            }

            $sale = Sale::with(['orderLines.product', 'payments.payment'])->findOrFail($id);
            $user = auth()->guard('api')->user();

            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('view.sales', 'api')) {
                return response()->json(['message' => 'Vous n\'avez pas la permission de voir cette vente.'], 403);
            }

            $isAdmin = $user->hasRole('admin', 'api');
            $isManager = $user->hasAnyRole(['gerant', 'gérant'], 'api');

            if (!$isAdmin) {
                if ($isManager) {
                    $pointOfSaleId = $user->point_of_sale_id;
                    if ($pointOfSaleId && (int) $sale->point_of_sale_id !== (int) $pointOfSaleId) {
                        return response()->json(['message' => 'Cette vente n\'appartient pas à votre point de vente.'], 403);
                    }
                    if (!$pointOfSaleId && (int) $sale->user_id !== (int) $user->id) {
                        return response()->json(['message' => 'Action non autorisée.'], 403);
                    }
                } else {
                    if ((int) $sale->user_id !== (int) $user->id) {
                        return response()->json(['message' => 'Action non autorisée.'], 403);
                    }
                }
            }

            return response()->json($sale);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        }
    }

    /**
     * PUT/PATCH /api/sales/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('edit.sales', 'api') && !$user->hasRole('admin', 'api')) {
                return response()->json(['message' => 'Vous n\'avez pas la permission de modifier une vente.'], 403);
            }

            if (!$user->hasRole('admin', 'api') && $this->userIsManager($user)) {
                return response()->json(['message' => 'Les gérants ne peuvent pas modifier une vente.'], 403);
            }

            $sale = Sale::findOrFail($id);

            $validatedData = $request->validate([
                'total_amount' => 'sometimes|numeric|min:0',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'status' => 'sometimes|string',
                'ticket_number' => [
                    'sometimes',
                    'integer',
                    Rule::unique('sales', 'ticket_number')
                        ->where(fn($query) => $query->where('cash_register_session_id', $sale->cash_register_session_id))
                        ->ignore($sale->id),
                ],
                'amount_received' => 'sometimes|nullable|numeric|min:0',
                'change_amount' => 'sometimes|nullable|numeric|min:0',
            ]);

            $totalAmount = isset($validatedData['total_amount']) ? (int) round($validatedData['total_amount']) : (int) $sale->total_amount;
            $discount = isset($validatedData['discount_percentage']) ? (int) round($validatedData['discount_percentage']) : (int) $sale->discount_percentage;
            $finalAmount = (int) round($totalAmount * (100 - $discount) / 100);

            $amountReceived = array_key_exists('amount_received', $validatedData) ? (int) round($validatedData['amount_received']) : ($sale->amount_received !== null ? (int) $sale->amount_received : null);
            $changeAmount = array_key_exists('change_amount', $validatedData) ? (int) round($validatedData['change_amount']) : ($sale->change_amount !== null ? (int) $sale->change_amount : null);

            $sale->update(array_merge($validatedData, [
                'total_amount' => $totalAmount,
                'discount_percentage' => $discount,
                'final_amount' => $finalAmount,
                'amount_received' => $amountReceived,
                'change_amount' => $changeAmount,
            ]));

            return response()->json($sale);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erreur de validation', 'details' => $e->errors()], 422);
        }
    }

    /**
     * DELETE /api/sales/{id}
     */
    public function destroy($id)
    {
        try {
            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('delete.sales', 'api') && !$user->hasRole('admin', 'api')) {
                return response()->json(['message' => 'Vous n\'avez pas la permission de supprimer une vente.'], 403);
            }

            if (!$user->hasRole('admin', 'api') && $this->userIsManager($user)) {
                return response()->json(['message' => 'Les gérants ne peuvent pas supprimer une vente.'], 403);
            }

            $sale = Sale::findOrFail($id);
            $sale->delete();

            return response()->json(['message' => 'Vente supprimée avec succès'], 204);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        }
    }

    /**
     * GET /api/sales/current-session
     */
    public function getSalesForCurrentSession(Request $request)
    {
        try {
            $user = $request->user();
            $cashRegisterSessionId = $request->query('cash_register_session_id');

            if (!$user || !$cashRegisterSessionId) {
                return response()->json(['error' => 'Session ou utilisateur invalide.'], 400);
            }

            $sales = Sale::with('orderLines.product')->where('cash_register_session_id', $cashRegisterSessionId)->get();

            return response()->json($sales);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erreur lors de la récupération des ventes.'], 500);
        }
    }

    /**
     * POST /api/sales/{saleId}/add-products
     */
    public function addToPendingOrder(Request $request, $saleId)
    {
        try {
            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            $validated = $request->validate([
                'order_lines' => 'required|array|min:1',
                'order_lines.*.product_id' => 'required|exists:products,id',
                'order_lines.*.quantity' => 'required|integer|min:1',
                'order_lines.*.price' => 'required|numeric|min:0',
            ]);

            $sale = Sale::findOrFail($saleId);
            $updatedSale = $this->saleService->addToPendingOrder($sale, $validated['order_lines']);

            return response()->json($updatedSale, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Commande non trouvée.'], 404);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erreur de validation', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/sales/{saleId}/remove-products
     */
    public function removeFromPendingOrder(Request $request, $saleId)
    {
        try {
            $validated = $request->validate([
                'order_line_ids' => 'required|array|min:1',
                'order_line_ids.*' => 'required|exists:order_lines,id',
            ]);

            $sale = Sale::findOrFail($saleId);
            $updatedSale = $this->saleService->removeFromPendingOrder($sale, $validated['order_line_ids']);

            return response()->json($updatedSale, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Commande non trouvée.'], 404);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erreur de validation', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/sales/{saleId}/cancel
     */
    public function cancelSale(Request $request, $saleId)
    {
        try {
            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            $validated = $request->validate([
                'reason' => 'nullable|string|max:255',
            ]);

            $sale = Sale::findOrFail($saleId);
            $cancelledSale = $this->saleService->cancelSale($sale, $validated['reason'] ?? null);

            return response()->json($cancelledSale, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erreur de validation', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/sales/{saleId}/formatted
     */
    public function getFormattedSale($saleId)
    {
        try {
            $sale = Sale::with([
                'orderLines.product.category',
                'user',
                'table',
                'pointOfSale',
                'payments.payment'
            ])->findOrFail($saleId);

            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('view.sales', 'api')) {
                return response()->json(['message' => 'Vous n\'avez pas la permission de voir cette vente.'], 403);
            }

            $formattedData = $this->saleService->getFormattedSaleData($sale);

            return response()->json([
                'success' => true,
                'data' => $formattedData
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/sales/{saleId}/categories
     */
    public function getSaleCategories($saleId)
    {
        try {
            $sale = Sale::with([
                'orderLines.product.category',
                'table',
                'user'
            ])->findOrFail($saleId);

            $user = auth()->guard('api')->user();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            if (!$user->hasPermissionTo('view.sales', 'api')) {
                return response()->json(['message' => 'Accès non autorisé.'], 403);
            }

            $categories = $this->saleService->getItemsGroupedByCategory($sale);

            return response()->json([
                'success' => true,
                'sale_id' => $sale->id,
                'ticket_number' => $sale->ticket_number,
                'table' => $sale->table?->table_number ?? 'Emporter',
                'cashier' => $sale->user?->name ?? 'Inconnu',
                'date' => $sale->created_at->format('d/m/Y H:i'),
                'categories' => $categories,
                'total_amount' => $sale->final_amount
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Vente non trouvée.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/sales/tables/{tableId}/pending-orders
     */
    public function getPendingOrdersForTable($tableId)
    {
        try {
            $orders = Sale::with(['orderLines.product', 'user'])
                ->where('table_id', $tableId)
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erreur lors de la récupération des commandes.'], 500);
        }
    }

    // ========== MÉTHODES PRIVÉES ==========

    private function userIsManager(?\App\Models\User $user): bool
    {
        if (!$user)
            return false;
        return $user->hasAnyRole(['gerant', 'gérant'], 'api');
    }
}