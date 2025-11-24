<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;

class FarmReportService
{
    /**
     * Create farm reports for multiple reportables
     */
    public function createReports(array $data, Farm $farm, User $user): array
    {
        $includeSubItems = $data['include_sub_items'] ?? false;
        $reports = [];

        foreach ($data['reportables'] as $reportableData) {
            $reportable = getModel($reportableData['type'], $reportableData['id']);

            // Create report for the main reportable
            $report = $this->createSingleReport($data, $farm, $user, $reportable);
            $reports[] = $report;

            // If include_sub_items is true, create reports for all sub-items
            if ($includeSubItems) {
                $subItems = $this->getSubItems($reportable);
                foreach ($subItems as $subItem) {
                    $subReport = $this->createSingleReport($data, $farm, $user, $subItem);
                    $reports[] = $subReport;
                }
            }
        }

        return $reports;
    }

    /**
     * Create a single farm report
     */
    public function createSingleReport(array $data, Farm $farm, User $user, $reportable): FarmReport
    {
        return FarmReport::create([
            'farm_id' => $farm->id,
            'date' => $data['date'],
            'operation_id' => $data['operation_id'],
            'labour_id' => $data['labour_id'],
            'description' => $data['description'],
            'value' => $data['value'],
            'created_by' => $user->id,
            'reportable_type' => get_class($reportable),
            'reportable_id' => $reportable->id,
        ]);
    }

    /**
     * Update farm reports for multiple reportables
     */
    public function updateReports(array $data, Farm $farm, User $user, FarmReport $originalReport = null): array
    {
        $includeSubItems = $data['include_sub_items'] ?? false;
        $updatedReports = [];

        foreach ($data['reportables'] as $reportableData) {
            $reportable = getModel($reportableData['type'], $reportableData['id']);

            // If this is the original reportable, update the original report
            if ($originalReport &&
                $originalReport->reportable_type === get_class($reportable) &&
                $originalReport->reportable_id === $reportable->id) {
                $report = $this->updateReport($originalReport, $data, $reportable);
            } else {
                // Find existing report for this reportable or create new one
                $report = $this->findOrCreateReport($data, $farm, $user, $reportable);
            }
            $updatedReports[] = $report;

            // If include_sub_items is true, update/create reports for all sub-items
            if ($includeSubItems) {
                $subItems = $this->getSubItems($reportable);
                foreach ($subItems as $subItem) {
                    $subReport = $this->findOrCreateReport($data, $farm, $user, $subItem);
                    $updatedReports[] = $subReport;
                }
            }
        }

        return $updatedReports;
    }

    /**
     * Update a single farm report
     */
    public function updateReport(FarmReport $report, array $data, $reportable): FarmReport
    {
        $report->update([
            'date' => $data['date'],
            'operation_id' => $data['operation_id'],
            'labour_id' => $data['labour_id'],
            'description' => $data['description'],
            'value' => $data['value'],
            'verified' => $data['verified'] ?? $report->verified,
            'reportable_type' => get_class($reportable),
            'reportable_id' => $reportable->id,
        ]);

        return $report;
    }

    /**
     * Find existing report or create new one for a reportable
     */
    public function findOrCreateReport(array $data, Farm $farm, User $user, $reportable): FarmReport
    {
        // Try to find existing report for this reportable
        $existingReport = FarmReport::where('farm_id', $farm->id)
            ->where('reportable_type', get_class($reportable))
            ->where('reportable_id', $reportable->id)
            ->where('date', $data['date'])
            ->where('operation_id', $data['operation_id'])
            ->where('labour_id', $data['labour_id'])
            ->first();

        if ($existingReport) {
            // Update existing report
            return $this->updateReport($existingReport, $data, $reportable);
        } else {
            // Create new report
            return $this->createSingleReport($data, $farm, $user, $reportable);
        }
    }

    /**
     * Verify a farm report
     */
    public function verifyReport(FarmReport $report): FarmReport
    {
        $report->update(['verified' => true]);
        return $report;
    }

    /**
     * Delete a farm report
     */
    public function deleteReport(FarmReport $report): bool
    {
        return $report->delete();
    }

    /**
     * Get all sub-items for a given reportable model
     */
    public function getSubItems($reportable): SupportCollection
    {
        $subItems = collect();

        switch (get_class($reportable)) {
            case \App\Models\Farm::class:
                // For farm, get all fields, plots, rows, and trees
                $subItems = $subItems->merge($reportable->fields);
                $subItems = $subItems->merge($reportable->plots);
                $subItems = $subItems->merge($reportable->rows);
                $subItems = $subItems->merge($reportable->trees);
                break;

            case \App\Models\Field::class:
                // For field, get all plots, rows, and trees
                $subItems = $subItems->merge($reportable->plots);
                $subItems = $subItems->merge($reportable->rows);
                $subItems = $subItems->merge($reportable->trees);
                break;

            case \App\Models\Plot::class:
                // For plot, get all rows and trees
                $subItems = $subItems->merge($reportable->rows);
                $subItems = $subItems->merge($reportable->trees);
                break;

            case \App\Models\Row::class:
                // For row, get all trees
                $subItems = $subItems->merge($reportable->trees);
                break;

            case \App\Models\Tree::class:
                // Trees have no sub-items
                break;
        }

        return $subItems;
    }

    /**
     * Get farm reports with filters
     */
    public function getFilteredReports(Farm $farm, array $filters): Collection
    {
        $query = $farm->reports()->with(['operation', 'labour', 'reportable']);

        // Apply filters dynamically
        foreach ($filters as $key => $value) {
            Log::info('Filter key', $key);
            Log::info('Filter value', $value);
            match ($key) {
                'reportable_type' => $query->where('reportable_type', 'App\\Models\\' . ucfirst($value)),
                'reportable_id' => $query->whereIn('reportable_id', $value),
                'operation_ids' => $query->whereIn('operation_id', $value),
                'labour_ids' => $query->whereIn('labour_id', $value),
                'date_range' => $query->whereDate('date', '>=', $value['from'])->whereDate('date', '<=', $value['to']),
                default => null,
            };
        }

        return $query->orderBy('date', 'desc')->get();
    }

    /**
     * Get paginated farm reports
     */
    public function getPaginatedReports(Farm $farm, int $perPage = 15)
    {
        return $farm->reports()
            ->latest()
            ->simplePaginate($perPage);
    }
}
