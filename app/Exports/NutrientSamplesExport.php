<?php

namespace App\Exports;

use App\Models\Farm;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class NutrientSamplesExport
{
    /**
     * The farm instance.
     *
     * @var Farm
     */
    protected $farm;

    /**
     * Create a new export instance.
     *
     * @param Farm $farm
     */
    public function __construct(Farm $farm)
    {
        $this->farm = $farm;
    }

    /**
     * Export all nutrient samples for the farm to an Excel file and return the file path.
     *
     * @return string The path to the generated Excel file
     */
    public function export(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headings
        $headings = $this->headings();
        $sheet->fromArray($headings, null, 'A1');

        // Get data rows
        $rows = $this->rows();
        $sheet->fromArray($rows, null, 'A2');

        // Save to a temporary file
        $filename = 'nutrient_samples_' . $this->farm->id . '_' . time() . '.xlsx';
        $tempPath = storage_path('app/tmp/' . $filename);
        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0777, true);
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        return $tempPath;
    }

    /**
     * Get the headings for the export.
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            __('ID'),
            __('Field ID'),
            __('Field Name'),
            __('Field Area'),
            __('Load Amount'),
            __('Nitrogen'),
            __('Phosphorus'),
            __('Potassium'),
            __('Calcium'),
            __('Magnesium'),
            __('Iron'),
            __('Copper'),
            __('Zinc'),
            __('Boron'),
            __('Username'),
            __('Mobile'),
            __('Farm Coordinates'),
            __('Farm Center'),
        ];
    }

    /**
     * Get all rows for the export.
     *
     * @return array
     */
    public function rows(): array
    {
        $samples = \App\Models\NutrientSample::whereHas('request', function ($query) {
                $query->where('farm_id', $this->farm->id);
            })
            ->with(['request.user', 'field'])
            ->get();

        $rows = [];
        foreach ($samples as $sample) {
            $user = $sample->request->user;
            $farm = $this->farm;
            $rows[] = [
                $sample->id,
                optional($sample->field)->id,
                optional($sample->field)->name,
                number_format($sample->field_area, 2),
                number_format($sample->load_amount, 2),
                number_format($sample->nitrogen, 2),
                number_format($sample->phosphorus, 2),
                number_format($sample->potassium, 2),
                number_format($sample->calcium, 2),
                number_format($sample->magnesium, 2),
                number_format($sample->iron, 2),
                number_format($sample->copper, 2),
                number_format($sample->zinc, 2),
                number_format($sample->boron, 2),
                $user?->username,
                $user?->mobile,
                json_encode($farm->coordinates),
                $farm->center,
            ];
        }
        return $rows;
    }
}
