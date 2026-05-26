<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Events\CashRegisterSessionOpened;
use App\Events\CashRegisterSessionClosed;
use Illuminate\Support\Facades\Auth;
use App\Services\CashRegisterSessionSummaryService;
use Illuminate\Support\Carbon;
use App\Models\User;
use Illuminate\Validation\Rule;


class CashRegisterSessionController extends Controller
{
    private function userIsManager(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $managerRoles = ['gerant', 'gérant'];
        return collect($managerRoles)->contains(fn($role) => $user->hasRole($role, 'api'));
    }

    /**
     * Display a listing of the cash register sessions.
     * Optional query parameter 'status' can be 'open' or 'closed' to filter sessions.
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('view.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }

        $query = CashRegisterSession::query();

        $managerRoles = ['gerant', 'gérant'];
        $isManager = collect($managerRoles)->contains(fn($role) => $user->hasRole($role, 'api'));

        if (!$user->hasRole('admin', 'api') && $isManager) {
            $pointOfSaleId = $user->point_of_sale_id;
            if ($pointOfSaleId) {
                $query->whereHas('cashRegister', function ($q) use ($pointOfSaleId) {
                    $q->where('point_of_sale_id', $pointOfSaleId);
                });
            } else {
                $query->where('user_id', $user->id);
            }
        } elseif (!$user->hasRole('admin', 'api') && !$isManager) {
            $query->where('user_id', $user->id);
        }

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        if ($request->has('status')) {
            if ($request->status === 'open') {
                $query->where('is_closed', false);
            } elseif ($request->status === 'closed') {
                $query->where('is_closed', true);
            }
        }

        if ($request->filled('cash_register_id')) {
            $query->where('cash_register_id', $request->cash_register_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $sessions = $query->with(['cashRegister', 'user', 'transactions', 'discrepancies', 'closures'])->get();

        return response()->json($sessions);
    }

    /**
     * Store a newly created cash register session (open session).
     */

    public function store(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();

        // 1. Vérification des permissions de base
        if (!$user || !$user->hasPermissionTo('create.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }

        // 2. Restriction métier : Les gérants ne peuvent pas ouvrir de session eux-mêmes
        if ($this->userIsManager($user)) {
            abort(403, 'Les gérants ne peuvent pas créer de session de caisse.');
        }

        // 3. Validation stricte
        $validated = $request->validate([
            'cash_register_id' => [
                'required',
                // On vérifie que la caisse existe ET appartient au même Point de Vente que l'utilisateur
                Rule::exists('cash_registers', 'id')->where(function ($query) use ($user) {
                    $query->where('point_of_sale_id', $user->point_of_sale_id);
                }),
            ],
            'starting_amount' => 'required|numeric|min:0',
            'expected_cash_amount' => 'nullable|numeric|min:0',
            'start_ticket_number' => 'nullable|integer|min:0',
        ]);

        // 4. Sécurité : On force l'user_id à être celui de l'utilisateur connecté
        $userId = $user->id;

        // 5. Vérification anti-doublon (Caisse déjà occupée ?)
        $openSession = CashRegisterSession::where('cash_register_id', $validated['cash_register_id'])
            ->where('is_closed', false)
            ->first();

        if ($openSession) {
            return response()->json([
                'message' => 'There is already an open session for this cash register.'
            ], Response::HTTP_CONFLICT);
        }

        // 6. Préparation du montant attendu
        $expectedAmount = $validated['expected_cash_amount'] ?? $validated['starting_amount'];

        // 7. Création de la session
        $session = CashRegisterSession::create([
            'cash_register_id' => $validated['cash_register_id'],
            'user_id' => $userId,
            'starting_amount' => $validated['starting_amount'],
            'expected_cash_amount' => $expectedAmount,
            'start_ticket_number' => $validated['start_ticket_number'] ?? null,
            'is_closed' => false,
            'opened_at' => now(),
        ]);

        // 8. Déclenchement de l'événement (pour les logs, notifications ou matériel)
        event(new CashRegisterSessionOpened($session));

        return response()->json($session, Response::HTTP_CREATED);
    }

    /**
     * Display the specified cash register session.
     */
    public function show($id, Request $request)
    {

        $query = CashRegisterSession::with(['cashRegister', 'user', 'transactions', 'discrepancies', 'closures']);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $session = $query->find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('view.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }

        $managerRoles = ['gerant', 'gérant'];
        $isManager = collect($managerRoles)->contains(fn($role) => $user->hasRole($role, 'api'));

        if (!$user->hasRole('admin', 'api') && $isManager) {
            $pointOfSaleId = $user->point_of_sale_id;
            if ($pointOfSaleId && optional($session->cashRegister)->point_of_sale_id !== $pointOfSaleId) {
                abort(403, 'This action is unauthorized.');
            }
            if (!$pointOfSaleId && $session->user_id !== $user->id) {
                abort(403, 'This action is unauthorized.');
            }
        } elseif (!$user->hasRole('admin', 'api') && $session->user_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }

        return response()->json($session);
    }

    /**
     * Update the specified cash register session.
     * This can be used to close the session by setting is_closed, actual_cash_amount, and closed_at.
     */
    public function update(Request $request, $id)
    {
        $session = CashRegisterSession::find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('update.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }
        if (!$user->hasRole('admin', 'api') && $this->userIsManager($user)) {
            abort(403, 'Les gérants ne peuvent pas modifier une session de caisse.');
        }

        $validated = $request->validate([
            'actual_cash_amount' => 'nullable|numeric|min:0',
            'expected_cash_amount' => 'nullable|numeric|min:0',
            'is_closed' => 'nullable|boolean',
            'closed_at' => 'nullable|date',
            'start_ticket_number' => 'nullable|integer|min:0',
        ]);

        $closedAt = null;
        if (isset($validated['closed_at'])) {
            try {
                $closedAt = Carbon::parse($validated['closed_at'])->timezone(config('app.timezone'))->toDateTimeString();
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid closed_at format. Expected a valid date string.'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (isset($validated['is_closed']) && $validated['is_closed'] === true) {
            // Closing the session requires actual_cash_amount and closed_at
            if (!isset($validated['actual_cash_amount']) || $closedAt === null) {
                return response()->json([
                    'message' => 'To close the session, actual_cash_amount and closed_at are required.'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $session->actual_cash_amount = $validated['actual_cash_amount'];
            $session->closed_at = $closedAt;
            $session->is_closed = true;
            if (isset($validated['expected_cash_amount'])) {
                $session->expected_cash_amount = $validated['expected_cash_amount'];
            }
            if (isset($validated['start_ticket_number'])) {
                $session->start_ticket_number = $validated['start_ticket_number'];
            }

            $session->save();

            event(new CashRegisterSessionClosed($session));

            return response()->json($session);
        } else {
            // Update other fields if provided
            if (isset($validated['actual_cash_amount'])) {
                $session->actual_cash_amount = $validated['actual_cash_amount'];
            }
            if (isset($validated['expected_cash_amount'])) {
                $session->expected_cash_amount = $validated['expected_cash_amount'];
            }
            if ($closedAt !== null) {
                $session->closed_at = $closedAt;
            }
            if (isset($validated['is_closed'])) {
                $session->is_closed = $validated['is_closed'];
            }
            if (isset($validated['start_ticket_number'])) {
                $session->start_ticket_number = $validated['start_ticket_number'];
            }

            $session->save();

            return response()->json($session);
        }
    }

    /**
     * Soft delete the specified cash register session.
     */
    public function destroy($id)
    {
        $session = CashRegisterSession::find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('delete.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }
        if (!$user->hasRole('admin', 'api') && $this->userIsManager($user)) {
            abort(403, 'Les gérants ne peuvent pas supprimer une session de caisse.');
        }

        $session->delete();

        return response()->json(['message' => 'Cash register session deleted successfully.']);
    }

    /**
     * Reopen a closed cash register session.
     */
    public function reopen($id)
    {
        $session = CashRegisterSession::find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('update.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }
        if (!$user->hasRole('admin', 'api') && $this->userIsManager($user)) {
            abort(403, 'Les gérants ne peuvent pas rouvrir une session de caisse.');
        }

        if (!$session->is_closed) {
            return response()->json(['message' => 'Session is already open.'], Response::HTTP_BAD_REQUEST);
        }

        $session->is_closed = false;
        $session->closed_at = null;
        $session->actual_cash_amount = null;
        $session->save();

        return response()->json($session);
    }

    /**
     * List discrepancies for a cash register session.
     */
    public function listDiscrepancies($id)
    {
        $session = CashRegisterSession::with('discrepancies')->find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('view.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }

        return response()->json($session->discrepancies);
    }

    /**
     * Add a discrepancy to a cash register session.
     */
    public function addDiscrepancy(Request $request, $id)
    {
        $session = CashRegisterSession::find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = auth()->guard('api')->user();
        if (!auth()->guard('api')->check() || !$user->hasPermissionTo('update.cash_register_sessions', 'api')) {
            abort(403, 'This action is unauthorized.');
        }

        $validated = $request->validate([
            'description' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        // On utilise les noms exacts des colonnes de ta base de données
        $discrepancy = $session->discrepancies()->create([
            'explanation' => $validated['description'],
            'difference_amount' => $validated['amount'],
        ]);

        return response()->json($discrepancy, Response::HTTP_CREATED);
    }

    /**
     * Get a summary report of the cash register session.
     */
    public function summary($id)
    {
        // 1. Chargement de la session avec sa caisse pour connaître son POS
        $session = CashRegisterSession::with(['cashRegister', 'transactions', 'discrepancies', 'closures', 'user'])
            ->find($id);

        if (!$session) {
            return response()->json(['message' => 'Cash register session not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user();

        // 2. Vérification des permissions (Doit avoir le droit de voir les sessions)
        if (!$user || !$user->hasPermissionTo('view.cash_register_sessions', 'api')) {
            abort(403, 'Action non autorisée.');
        }

        // 3. LOGIQUE DE FILTRAGE PAR POINT DE VENTE (POS)
        if (!$user->hasRole('admin', 'api')) {
            // Si c'est un gérant, on vérifie que le POS de la caisse est le sien
            $userPosId = $user->point_of_sale_id;
            $sessionPosId = $session->cashRegister ? $session->cashRegister->point_of_sale_id : null;

            if ($userPosId !== $sessionPosId) {
                abort(403, 'Accès refusé : Vous ne pouvez voir que les ventes de votre propre point de vente.');
            }
        }
        // Si c'est un Admin, le code continue sans bloquer (Accès total)

        // 4. Sécurité métier : La session doit être fermée pour voir le résumé final
        if (!$session->is_closed) {
            return response()->json([
                'message' => 'Le résumé ne peut être consulté que pour une session fermée.'
            ], Response::HTTP_CONFLICT);
        }

        // 5. Utilisation du Service (qui gère déjà le masquage de l'écart de caisse pour le gérant)
        $service = new CashRegisterSessionSummaryService();
        $summary = $service->build($session);

        // Ajout des détails de billetage (closures)
        $summary['closures'] = $session->closures;

        return response()->json($summary);
    }

    /**
     * Get the status of a specific cash register session.
     */
    public function status($cashRegisterId)
    {
        // Rechercher la session ouverte pour cette caisse
        $session = CashRegisterSession::where('cash_register_id', $cashRegisterId)
            ->where('is_closed', false)
            ->latest('opened_at')
            ->first();

        if ($session) {
            return response()->json([
                'status' => 'in use',
                'opened_at' => $session->opened_at,
                'user' => $session->user->name,
                'session_id' => $session->id
            ]);
        } else {
            return response()->json([
                'status' => 'available',
                'message' => 'Cette caisse est libre, aucune session active.'
            ]);
        }
    }
    public function myActiveSession()
    {

        // Vérifier que l'utilisateur est connecté
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (!$user = Auth::user()) {
            abort(401, 'Utilisateur non authentifié.');
        }

        // Rechercher la session active pour l'utilisateur connecté
        $session = CashRegisterSession::where('user_id', $user->id)
            ->where('is_closed', false)
            ->with(['cashRegister', 'user'])
            ->first();

        if ($session) {
            return response()->json([
                'data' => $session
            ]);
        }

        return response()->json([
            'data' => null,
            'message' => ' Aucune session active'
        ], 404);
    }

}
