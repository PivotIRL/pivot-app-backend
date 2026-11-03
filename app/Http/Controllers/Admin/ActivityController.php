<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Hobby; // Import Hobby Model
use App\Http\Requests\Admin\ActivityRequest;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::with('hobby');

        if ($request->filled('search')) {
            $search = strtolower(trim($request->search));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(activity_title) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('tier')) {
            $tier = strtolower(trim($request->tier));
            $query->whereRaw('LOWER(tier) LIKE ?', ["%{$tier}%"]);
        }

        $activities = $query->orderBy('created_at', 'desc')->paginate(10);
        $hobbies = Hobby::orderBy('name', 'asc')->get(); // Fetch categories/hobbies

        return view('admin.activities.index', compact('activities', 'hobbies'));
    }

    public function export()
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $columns = [
            'id' => 'ID',
            'hobby_name' => 'Category Name',
            'activity_title' => 'Title',
            'description' => 'Description',
            'time' => 'Time',
            'instruction' => 'Instructions',
            'activity_type' => 'Activity Type',
            'subcategory' => 'Subcategory',
            'duration_minutes' => 'Duration (minutes)',
            'tier' => 'Tier',
            'cost' => 'Cost',
            'location' => 'Location',
            'min_age' => 'Minimum Age',
            'max_age' => 'Maximum Age',
            'neurodivergent_friendly' => 'Neurodivergent Friendly',
            'neurodivergent_notes' => 'Neurodivergent Notes',
            'sensory_tags' => 'Sensory Tags',
            'social_type' => 'Social Type',
            'energy_level' => 'Energy Level',
            'outcome_tag' => 'Outcome Tag',
            'mood_match' => 'Mood Match',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activities');

        foreach (array_values($columns) as $index => $heading) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '1', $heading);
        }

        $row = 2;
        Activity::with('hobby')->orderBy('id')->chunk(500, function ($activities) use ($sheet, $columns, &$row) {
            foreach ($activities as $activity) {
                $values = $activity->getAttributes();
                $values['hobby_name'] = $activity->hobby?->name;

                foreach (array_keys($columns) as $index => $key) {
                    $value = $values[$key] ?? null;
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    $sheet->setCellValueExplicit(
                        Coordinate::stringFromColumnIndex($index + 1) . $row,
                        $value === null ? '' : (string) $value,
                        DataType::TYPE_STRING
                    );
                }

                $row++;
            }
        });

        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true);

        $filename = 'activities-' . now()->format('Y-m-d-His') . '.xlsx';
        $tempPath = storage_path('app/public/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function show($id)
    {
        $activity = Activity::with('hobby')->findOrFail($id);
        return view('admin.activities.show', compact('activity'));
    }

    public function store(ActivityRequest $request)
    {
        $data = $request->validated();

        Activity::create($data);

        return redirect()->route('admin.activities.index')->with('success', 'Activity created successfully.');
    }

    public function update(ActivityRequest $request, $id)
    {
        $activity = Activity::findOrFail($id);
        $data = $request->validated();

        $activity->update($data);

        return redirect()->route('admin.activities.index')->with('success', 'Activity updated successfully.');
    }

    public function destroy($id)
    {
        $activity = Activity::findOrFail($id);
        $activity->delete();

        return redirect()->route('admin.activities.index')->with('success', 'Activity deleted successfully.');
    }
}
