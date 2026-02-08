<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SearchService
{
    /**
     * Available resource types and their configurations
     */
    protected array $resourceConfigs = [
        'users' => [
            'model' => \App\Models\User::class,
            'searchable_columns' => ['mobile'],
            'resource' => \App\Http\Resources\UserResource::class,
            'with' => ['profile', 'farms'],
            'scope_method' => 'scopeUsers',
        ],
        'crops' => [
            'model' => \App\Models\Crop::class,
            'searchable_columns' => ['name'],
            'resource' => \App\Http\Resources\CropResource::class,
            'with' => ['creator'],
            'scope_method' => 'scopeCrops',
        ],
        'crop_types' => [
            'model' => \App\Models\CropType::class,
            'searchable_columns' => ['name'],
            'resource' => \App\Http\Resources\CropTypeResource::class,
            'with' => ['creator'],
            'scope_method' => 'scopeCropTypes',
        ],
        'labours' => [
            'model' => \App\Models\Labour::class,
            'searchable_columns' => ['name', 'personnel_number', 'mobile'],
            'resource' => \App\Http\Resources\LabourResource::class,
            'with' => ['currentShiftSchedule.shift'],
            'scope_method' => 'scopeLabours',
        ],
        'teams' => [
            'model' => \App\Models\Team::class,
            'searchable_columns' => ['name'],
            'resource' => \App\Http\Resources\TeamResource::class,
            'with' => ['supervisor'],
            'scope_method' => 'scopeTeams',
        ],
        'maintenances' => [
            'model' => \App\Models\Maintenance::class,
            'searchable_columns' => ['name'],
            'resource' => \App\Http\Resources\MaintenanceResource::class,
            'with' => [],
            'scope_method' => 'scopeMaintenances',
        ],
    ];

    /**
     * Search for resources across multiple types or a specific type
     *
     * @param string $query The search query
     * @param User $user The authenticated user
     * @param string|null $type The resource type to search (null for all types)
     * @param array $filters Additional filters
     * @return Collection
     */
    public function search(string $query, User $user, ?string $type = null, array $filters = []): Collection
    {
        if ($type) {
            $this->validateResourceType($type);
            return $this->searchResourceType($type, $query, $user, $filters);
        }

        // Search across all resource types
        $results = collect();
        foreach ($this->resourceConfigs as $resourceType => $config) {
            $typeResults = $this->searchResourceType($resourceType, $query, $user, $filters);
            if ($typeResults->isNotEmpty()) {
                $results->put($resourceType, $typeResults);
            }
        }

        return $results;
    }

    /**
     * Search a specific resource type
     *
     * @param string $type
     * @param string $query
     * @param User $user
     * @param array $filters
     * @return Collection
     */
    protected function searchResourceType(string $type, string $query, User $user, array $filters = []): Collection
    {
        $config = $this->resourceConfigs[$type];
        $modelClass = $config['model'];

        // Start building the query
        $builder = $modelClass::query();

        // Apply user-specific scoping
        $builder = $this->{$config['scope_method']}($builder, $user, $filters);

        // Apply search to configured columns
        $builder->where(function (Builder $q) use ($query, $config) {
            foreach ($config['searchable_columns'] as $column) {
                $q->orWhere($column, 'like', "%{$query}%");
            }
        });

        // Load relationships
        if (!empty($config['with'])) {
            $builder->with($config['with']);
        }

        // Get results
        $results = $builder->get();

        // Transform to resource if configured
        if (isset($config['resource'])) {
            $resourceClass = $config['resource'];
            return $resourceClass::collection($results)->collection;
        }

        return $results;
    }

    /**
     * Scope users query based on user permissions
     */
    protected function scopeUsers(Builder $query, User $user, array $filters): Builder
    {
        // Exclude the current user
        $query->where('id', '!=', $user->id);

        // Exclude super-admin users from search
        $query->withoutRole('super-admin');

        // If user is admin or super-admin, filter by created_by
        if ($user->hasAnyRole(['admin', 'super-admin'])) {
            $query->where('created_by', $user->id);
        }

        // Apply working environment filter if not root
        if (!$user->hasRole('root') && !isset($filters['skip_working_environment'])) {
            $workingEnvironment = $user->workingEnvironment();
            if ($workingEnvironment) {
                $query->with('farms');
            }
        }

        return $query;
    }

    /**
     * Scope crops query based on user permissions
     */
    protected function scopeCrops(Builder $query, User $user, array $filters): Builder
    {
        if ($user->hasRole('root')) {
            $query->where('created_by', null);
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhereNull('created_by');
            });
        }

        // Apply active filter if provided
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query;
    }

    /**
     * Scope crop types query based on user permissions
     */
    protected function scopeCropTypes(Builder $query, User $user, array $filters): Builder
    {
        // Filter by crop_id if provided
        if (isset($filters['crop_id'])) {
            $query->where('crop_id', $filters['crop_id']);
        }

        if ($user->hasRole('root')) {
            $query->where('created_by', null);
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhereNull('created_by');
            });
        }

        // Apply active filter if provided
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query;
    }

    /**
     * Scope labours query based on user permissions
     */
    protected function scopeLabours(Builder $query, User $user, array $filters): Builder
    {
        // Filter by farm_id if provided
        if (isset($filters['farm_id'])) {
            $query->where('farm_id', $filters['farm_id']);
        }

        return $query;
    }

    /**
     * Scope teams query based on user permissions
     */
    protected function scopeTeams(Builder $query, User $user, array $filters): Builder
    {
        // Filter by farm_id if provided
        if (isset($filters['farm_id'])) {
            $query->where('farm_id', $filters['farm_id']);
        }

        $query->withCount('labours');

        return $query;
    }

    /**
     * Scope maintenances query based on user permissions
     */
    protected function scopeMaintenances(Builder $query, User $user, array $filters): Builder
    {
        // Filter by farm_id if provided
        if (isset($filters['farm_id'])) {
            $query->where('farm_id', $filters['farm_id']);
        }

        return $query;
    }

    /**
     * Validate that a resource type is supported
     *
     * @param string $type
     * @throws InvalidArgumentException
     */
    protected function validateResourceType(string $type): void
    {
        if (!isset($this->resourceConfigs[$type])) {
            throw new InvalidArgumentException(
                "Resource type '{$type}' is not supported. Available types: " .
                implode(', ', array_keys($this->resourceConfigs))
            );
        }
    }

    /**
     * Get all available resource types
     *
     * @return array
     */
    public function getAvailableResourceTypes(): array
    {
        return array_keys($this->resourceConfigs);
    }

    /**
     * Register a new resource type for searching
     * This allows extending the search service with new resource types
     *
     * @param string $type
     * @param array $config
     * @return void
     */
    public function registerResourceType(string $type, array $config): void
    {
        // Validate required config keys
        $requiredKeys = ['model', 'searchable_columns', 'scope_method'];
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("Config key '{$key}' is required for resource type '{$type}'");
            }
        }

        $this->resourceConfigs[$type] = array_merge([
            'resource' => null,
            'with' => [],
        ], $config);
    }
}
