<?php

declare(strict_types=1);

namespace App\Contracts\Document;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Interface for document filtering and search service
 * 
 * Responsible for building complex queries, applying filters,
 * and paginating document results.
 * 
 * @package App\Contracts\Document
 */
interface DocumentFilterServiceInterface
{
    /**
     * Apply filters to a query builder
     * 
     * Supports filters:
     * - knowledge_base_id
     * - ingestion_status
     * - source_url (ILIKE search)
     * - created_at (date range)
     * - mime_type
     * 
     * @param Builder $query Base query builder
     * @param array<string, mixed> $filters Filter parameters
     * @param int $tenantId Tenant ID (always applied)
     * @return Builder Filtered query builder
     */
    public function applyFilters(Builder $query, array $filters, int $tenantId): Builder;

    /**
     * Apply search on document name and source URL
     * 
     * @param Builder $query Query builder
     * @param string $searchTerm Search term
     * @return Builder Query with search applied
     */
    public function applySearch(Builder $query, string $searchTerm): Builder;

    /**
     * Apply sorting
     * 
     * @param Builder $query Query builder
     * @param string $sortBy Field to sort by (created_at, name, ingestion_status)
     * @param string $sortDirection Direction (asc, desc)
     * @return Builder Query with sorting applied
     */
    public function applySort(Builder $query, string $sortBy = 'created_at', string $sortDirection = 'desc'): Builder;

    /**
     * Paginate query results
     * 
     * @param Builder $query Filtered and sorted query
     * @param int $perPage Items per page (default: 50)
     * @return LengthAwarePaginator Paginated results
     */
    public function paginate(Builder $query, int $perPage = 50): LengthAwarePaginator;

    /**
     * Build a complete filtered and paginated query
     * 
     * Convenience method that combines all operations.
     * 
     * @param array<string, mixed> $filters Filters
     * @param int $tenantId Tenant ID
     * @param int $perPage Items per page
     * @return LengthAwarePaginator Paginated results
     */
    public function buildQuery(array $filters, int $tenantId, int $perPage = 50): LengthAwarePaginator;
}

