<?php

namespace App\Console\Commands;

use App\Models\TableOfSpecification;
use App\Services\Bloom\BloomClassifierService;
use App\Services\Bloom\ItemPlacementService;
use App\Services\Bloom\TosBuilderService;
use App\Services\Bloom\TosConsolePrinter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProcessTosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tos:process
                            {--course= : Course name (e.g. "Computer Organization and Architecture")}
                            {--items=50 : Total exam items}
                            {--pdf= : Path to lecture/material PDF file}
                            {--objectives= : Intended learning objectives text (lines separated by \n)}
                            {--objectives-file= : Path to file containing objectives (one per line)}
                            {--title= : Lesson / Topic title}
                            {--weight=3 : Instructional time in hours}
                            {--output= : Path to save generated TOS PDF file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process learning objectives and PDF to build TOS, print terminal results, and generate TOS Table PDF';

    public function handle(
        BloomClassifierService $classifier,
        TosBuilderService $tosBuilder,
        ItemPlacementService $itemPlacement,
        TosConsolePrinter $printer
    ): int {
        $course = $this->option('course') ?: 'Computer Architecture & Number Systems';
        $totalItems = (int) ($this->option('items') ?: 50);
        $title = $this->option('title') ?: 'Common Number Systems and Conversions';
        $weight = (float) ($this->option('weight') ?: 3.0);

        // Resolve objectives
        $objectivesText = $this->option('objectives');
        if (! $objectivesText && $this->option('objectives-file')) {
            $filePath = $this->option('objectives-file');
            if (File::exists($filePath)) {
                $objectivesText = File::get($filePath);
            } else {
                $this->error("Objectives file not found: {$filePath}");

                return self::FAILURE;
            }
        }

        if (! $objectivesText) {
            // Default sample objectives matching curriculum format if none provided
            $objectivesText = "ILO 1. Define the number system and its properties\n"
                ."ILO 2. Differentiate binary numbers to decimal, octal and hexadecimal\n"
                ."ILO 3. Convert binary numbers into other number systems\n"
                .'ILO 4. Analyze the principles of numbers versus numeration';
        }

        // Resolve PDF
        $pdfPath = $this->option('pdf');
        $storedPdfPath = 'lesson_pdfs/cli_sample_'.uniqid().'.pdf';

        if ($pdfPath && File::exists($pdfPath)) {
            $destDir = storage_path('app/private/lesson_pdfs');
            File::ensureDirectoryExists($destDir);
            $destFile = $destDir.DIRECTORY_SEPARATOR.basename($storedPdfPath);
            File::copy($pdfPath, $destFile);
        } else {
            // Create a minimal placeholder PDF if no file was passed
            $destDir = storage_path('app/private/lesson_pdfs');
            File::ensureDirectoryExists($destDir);
            $destFile = $destDir.DIRECTORY_SEPARATOR.basename($storedPdfPath);
            File::put($destFile, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF");
        }

        $lessonWeights = [
            0 => ['title' => $title, 'weight' => $weight],
        ];

        $allocation = $tosBuilder->buildAcrossLessons($lessonWeights, $totalItems);

        $objectivesList = collect(explode("\n", str_replace("\r", '', $objectivesText)))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        $classified = $classifier->classifyMany($objectivesList);

        $tos = DB::transaction(function () use ($course, $totalItems, $allocation, $title, $weight, $storedPdfPath, $classified) {
            $tos = TableOfSpecification::create([
                'course' => $course,
                'total_items' => $totalItems,
                'distribution' => $allocation['aggregate_distribution'],
                'user_id' => null,
            ]);

            $lesson = $tos->lessons()->create([
                'title' => $title,
                'weight' => $weight,
                'pdf_path' => $storedPdfPath,
                'item_quota' => $allocation['items_per_lesson'][0],
                'level_distribution' => $allocation['levels_per_lesson'][0],
                'sort_order' => 0,
            ]);

            foreach ($classified as $item) {
                $lesson->objectives()->create([
                    'tos_id' => $tos->id,
                    'objective_text' => $item['objective'],
                    'bloom_level' => $item['level'],
                    'bloom_level_index' => $item['level_index'],
                    'confidence' => $item['confidence'],
                    'all_probabilities' => $item['all_probabilities'],
                ]);
            }

            return $tos;
        });

        // Generate the TOS table PDF
        $tos->load('lessons.objectives', 'lessons.examQuestions');
        $itemPlacementGrid = $itemPlacement->groupGrid($tos);

        $pdf = Pdf::loadView('tos.pdf', [
            'tos' => $tos,
            'itemPlacementGrid' => $itemPlacementGrid,
            'meta' => [
                'course_outcome' => $course,
            ],
        ])->setPaper('legal', 'landscape');

        $outputPath = $this->option('output');
        if (! $outputPath) {
            $exportDir = storage_path('app/private/tos_pdfs');
            File::ensureDirectoryExists($exportDir);
            $outputPath = $exportDir.DIRECTORY_SEPARATOR.'TOS-'.Str::slug($course).'-'.$tos->uuid.'.pdf';
        } else {
            File::ensureDirectoryExists(dirname($outputPath));
        }

        File::put($outputPath, $pdf->output());

        // Output results to terminal print
        $printer->print($tos, $this->output, $outputPath);

        $this->info("Successfully generated TOS Table PDF at: {$outputPath}");

        return self::SUCCESS;
    }
}
