<?php

namespace App\Services;

use App\Models\HandoffRequest;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OperatorRoutingService
{
    /**
     * 🎯 Trova il miglior operatore per un handoff request
     */
    public function findBestOperator(HandoffRequest $handoffRequest): ?User
    {
        try {
            $tenant = $handoffRequest->tenant;
            $availableOperators = $this->getAvailableOperators($tenant);

            if ($availableOperators->isEmpty()) {
                Log::warning('routing.no_operators_available', [
                    'tenant_id' => $tenant->id,
                    'handoff_id' => $handoffRequest->id
                ]);
                return null;
            }

            // 🎯 Applica criteri di routing
            $scoredOperators = $this->scoreOperators($availableOperators, $handoffRequest);
            
            // 📊 Seleziona il migliore
            $bestOperator = $scoredOperators->sortByDesc('score')->first();

            Log::info('routing.operator_selected', [
                'tenant_id' => $tenant->id,
                'handoff_id' => $handoffRequest->id,
                'operator_id' => $bestOperator['operator']->id,
                'score' => $bestOperator['score'],
                'criteria' => $bestOperator['criteria']
            ]);

            return $bestOperator['operator'];

        } catch (\Exception $e) {
            Log::error('routing.selection_failed', [
                'handoff_id' => $handoffRequest->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 🎲 Assegnazione automatica con round-robin intelligente
     */
    public function autoAssignHandoff(HandoffRequest $handoffRequest): bool
    {
        try {
            $operator = $this->findBestOperator($handoffRequest);

            if (!$operator) {
                // 📦 Metti in coda se nessun operatore disponibile
                $this->queueHandoffRequest($handoffRequest);
                return false;
            }

            // 🔄 Usa HandoffService per assegnazione
            $handoffService = app(HandoffService::class);
            return $handoffService->assignToOperator($handoffRequest, $operator);

        } catch (\Exception $e) {
            Log::error('routing.auto_assign_failed', [
                'handoff_id' => $handoffRequest->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 👥 Ottieni operatori disponibili per tenant
     */
    public function getAvailableOperators(Tenant $tenant): Collection
    {
        return User::operators()
                  ->availableOperators()
                  ->where(function($query) use ($tenant) {
                      // 🔍 Operatori del tenant o globali
                      $query->whereHas('tenants', function($q) use ($tenant) {
                          $q->where('tenant_id', $tenant->id);
                      })->orWhere('user_type', 'admin'); // Admin globali disponibili ovunque
                  })
                  ->where('current_conversations', '<', \DB::raw('max_concurrent_conversations'))
                  ->get();
    }

    /**
     * 📊 Assegna punteggio agli operatori basato su criteri
     */
    private function scoreOperators(Collection $operators, HandoffRequest $handoffRequest): Collection
    {
        return $operators->map(function($operator) use ($handoffRequest) {
            $score = 0;
            $criteria = [];

            // 🎯 CRITERIO 1: Competenze (30 punti max)
            $skillMatch = $this->calculateSkillMatch($operator, $handoffRequest);
            $score += $skillMatch;
            $criteria['skill_match'] = $skillMatch;

            // ⚖️ CRITERIO 2: Carico di lavoro (25 punti max)
            $workloadScore = $this->calculateWorkloadScore($operator);
            $score += $workloadScore;
            $criteria['workload_score'] = $workloadScore;

            // ⏱️ CRITERIO 3: Tempo risposta medio (20 punti max)
            $responseTimeScore = $this->calculateResponseTimeScore($operator);
            $score += $responseTimeScore;
            $criteria['response_time_score'] = $responseTimeScore;

            // 😊 CRITERIO 4: Soddisfazione clienti (15 punti max)
            $satisfactionScore = $this->calculateSatisfactionScore($operator);
            $score += $satisfactionScore;
            $criteria['satisfaction_score'] = $satisfactionScore;

            // 🔥 CRITERIO 5: Priorità handoff (10 punti max)
            $priorityBonus = $this->calculatePriorityBonus($operator, $handoffRequest);
            $score += $priorityBonus;
            $criteria['priority_bonus'] = $priorityBonus;

            return [
                'operator' => $operator,
                'score' => $score,
                'criteria' => $criteria
            ];
        });
    }

    /**
     * 🎯 Calcola match competenze operatore vs richiesta
     */
    private function calculateSkillMatch(User $operator, HandoffRequest $handoffRequest): float
    {
        $operatorSkills = $operator->getSkillsArray();
        $requiredSkills = $handoffRequest->routing_criteria['required_skills'] ?? [];
        $preferredSkills = $handoffRequest->routing_criteria['preferred_skills'] ?? [];

        if (empty($requiredSkills) && empty($preferredSkills)) {
            return 15; // Score base se non ci sono requisiti specifici
        }

        $score = 0;

        // ✅ Skills richieste (20 punti se tutte presenti)
        if (!empty($requiredSkills)) {
            $matchedRequired = count(array_intersect($operatorSkills, $requiredSkills));
            $score += ($matchedRequired / count($requiredSkills)) * 20;
        } else {
            $score += 20; // Bonus se non ci sono skills richieste
        }

        // ⭐ Skills preferite (10 punti bonus)
        if (!empty($preferredSkills)) {
            $matchedPreferred = count(array_intersect($operatorSkills, $preferredSkills));
            $score += ($matchedPreferred / count($preferredSkills)) * 10;
        }

        return min($score, 30);
    }

    /**
     * ⚖️ Calcola score basato su carico di lavoro
     */
    private function calculateWorkloadScore(User $operator): float
    {
        $utilizationRate = $operator->max_concurrent_conversations > 0 
            ? ($operator->current_conversations / $operator->max_concurrent_conversations) 
            : 0;

        // 📈 Più basso il carico, più alto il punteggio
        return (1 - $utilizationRate) * 25;
    }

    /**
     * ⏱️ Calcola score basato su tempo di risposta medio
     */
    private function calculateResponseTimeScore(User $operator): float
    {
        $avgResponseTime = $operator->average_response_time_minutes ?? 5;
        
        // 🚀 Meno di 2 minuti = massimo score
        // 📈 Score decresce linearmente fino a 10 minuti
        if ($avgResponseTime <= 2) {
            return 20;
        } elseif ($avgResponseTime <= 10) {
            return 20 - (($avgResponseTime - 2) / 8) * 20;
        } else {
            return 0;
        }
    }

    /**
     * 😊 Calcola score basato su soddisfazione clienti
     */
    private function calculateSatisfactionScore(User $operator): float
    {
        $avgSatisfaction = $operator->customer_satisfaction_avg ?? 3.5;
        
        // 🌟 5.0 = 15 punti, 4.0 = 10 punti, 3.0 = 5 punti, <3.0 = 0 punti
        if ($avgSatisfaction >= 4.5) {
            return 15;
        } elseif ($avgSatisfaction >= 4.0) {
            return 12;
        } elseif ($avgSatisfaction >= 3.5) {
            return 8;
        } elseif ($avgSatisfaction >= 3.0) {
            return 5;
        } else {
            return 0;
        }
    }

    /**
     * 🔥 Calcola bonus per priorità handoff
     */
    private function calculatePriorityBonus(User $operator, HandoffRequest $handoffRequest): float
    {
        // 🚨 Operatori esperti get bonus per urgent requests
        $operatorExperience = $operator->total_conversations_handled ?? 0;
        
        $bonus = 0;
        
        if ($handoffRequest->priority === 'urgent' && $operatorExperience > 50) {
            $bonus += 10;
        } elseif ($handoffRequest->priority === 'high' && $operatorExperience > 20) {
            $bonus += 5;
        }

        return $bonus;
    }

    /**
     * 📦 Mette handoff in coda quando nessun operatore disponibile
     */
    private function queueHandoffRequest(HandoffRequest $handoffRequest): void
    {
        $handoffRequest->update([
            'status' => 'pending',
            'metadata' => array_merge($handoffRequest->metadata ?? [], [
                'queued_at' => now()->toISOString(),
                'queue_reason' => 'no_operators_available'
            ])
        ]);

        Log::info('routing.handoff_queued', [
            'handoff_id' => $handoffRequest->id,
            'tenant_id' => $handoffRequest->tenant_id,
            'priority' => $handoffRequest->priority
        ]);
    }

    /**
     * 🔄 Processa coda handoff quando operatore diventa disponibile
     */
    public function processHandoffQueue(User $operator): int
    {
        $assignedCount = 0;

        try {
            if (!$operator->canTakeNewConversation()) {
                return 0;
            }

            // 🔍 Trova handoff in coda per tenant dell'operatore
            $operatorTenants = $operator->tenants->pluck('id');
            
            $queuedHandoffs = HandoffRequest::where('status', 'pending')
                                          ->whereIn('tenant_id', $operatorTenants)
                                          ->orderBy('priority')
                                          ->orderBy('requested_at')
                                          ->limit(5) // Processa max 5 alla volta
                                          ->get();

            foreach ($queuedHandoffs as $handoffRequest) {
                if (!$operator->canTakeNewConversation()) {
                    break; // Operatore ha raggiunto limite
                }

                // 🎯 Verifica se operatore è adatto per questo handoff
                $scoredOperator = $this->scoreOperators(collect([$operator]), $handoffRequest)->first();
                
                if ($scoredOperator['score'] >= 30) { // Soglia minima
                    $handoffService = app(HandoffService::class);
                    $success = $handoffService->assignToOperator($handoffRequest, $operator);
                    
                    if ($success) {
                        $assignedCount++;
                        Log::info('routing.queue_processed', [
                            'handoff_id' => $handoffRequest->id,
                            'operator_id' => $operator->id
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('routing.queue_processing_failed', [
                'operator_id' => $operator->id,
                'error' => $e->getMessage()
            ]);
        }

        return $assignedCount;
    }

    /**
     * 📊 Ottieni statistiche routing per tenant
     */
    public function getRoutingStats(int $tenantId, ?\DateTime $since = null): array
    {
        try {
            $query = HandoffRequest::where('tenant_id', $tenantId);

            if ($since) {
                $query->where('requested_at', '>=', $since);
            }

            $totalHandoffs = $query->count();
            $autoAssigned = $query->whereNotNull('assigned_operator_id')
                                 ->whereColumn('assigned_at', '<=', \DB::raw('requested_at + INTERVAL 5 MINUTE'))
                                 ->count();

            $avgAssignmentTime = $query->whereNotNull('wait_time_seconds')
                                     ->avg('wait_time_seconds') ?? 0;

            $operatorUtilization = User::operators()
                                     ->whereHas('tenants', function($q) use ($tenantId) {
                                         $q->where('tenant_id', $tenantId);
                                     })
                                     ->selectRaw('AVG(current_conversations / max_concurrent_conversations * 100) as avg_utilization')
                                     ->value('avg_utilization') ?? 0;

            return [
                'total_handoffs' => $totalHandoffs,
                'auto_assigned_count' => $autoAssigned,
                'auto_assignment_rate' => $totalHandoffs > 0 ? ($autoAssigned / $totalHandoffs) * 100 : 0,
                'avg_assignment_time_minutes' => round($avgAssignmentTime / 60, 2),
                'operator_utilization_percent' => round($operatorUtilization, 2)
            ];

        } catch (\Exception $e) {
            Log::error('routing.stats_failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_handoffs' => 0,
                'auto_assigned_count' => 0,
                'auto_assignment_rate' => 0,
                'avg_assignment_time_minutes' => 0,
                'operator_utilization_percent' => 0
            ];
        }
    }
}
