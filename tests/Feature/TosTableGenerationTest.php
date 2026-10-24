<?php

namespace Tests\Feature;

use App\Models\TableOfSpecification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TosTableGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $realModelPath = storage_path('app/private/ml/bloom_model.json');
        $modelContent = File::exists($realModelPath) ? File::get($realModelPath) : null;

        Storage::fake('local');

        if ($modelContent !== null) {
            Storage::put('ml/bloom_model.json', $modelContent);
        }
    }

    public function test_web_request_processes_objectives_and_pdf_and_returns_tos_table_results(): void
    {
        $fakePdf = UploadedFile::fake()->create('digital_circuits.pdf', 200, 'application/pdf');

        $payload = [
            'course' => 'Digital Circuits & Logic Design',
            'total_items' => 50,
            'lessons' => [
                [
                    'title' => 'Common Number Systems and Conversions',
                    'weight' => 3.0,
                    'objectives_text' => "Define the number system\nDifferentiate binary numbers to decimal and hexadecimal\nConvert binary numbers into other number systems",
                    'pdf' => $fakePdf,
                ],
                [
                    'title' => 'Boolean Algebra & Logic Gates',
                    'weight' => 2.0,
                    'objectives_text' => "Know the basic logic operations\nLearn how to create truth tables and prove theorems",
                    'pdf' => $fakePdf,
                ],
            ],
        ];

        $response = $this->postJson(route('tos.store'), $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'html',
            'tos_id',
            'status',
        ]);

        // Verify HTML contains the TOS table download action
        $html = $response->json('html');
        $this->assertStringContainsString('Download TOS Table (PDF)', $html);
        $this->assertStringContainsString('Common Number Systems and Conversions', $html);
        $this->assertStringContainsString('Boolean Algebra', $html);

        // Verify database persistence
        $this->assertDatabaseHas('table_of_specifications', [
            'course' => 'Digital Circuits & Logic Design',
            'total_items' => 50,
        ]);

        $tos = TableOfSpecification::first();
        $this->assertNotNull($tos);
        $this->assertCount(2, $tos->lessons);

        // Verify objectives classification
        $this->assertGreaterThan(0, $tos->objectives()->count());
        foreach ($tos->objectives as $obj) {
            $this->assertNotEmpty($obj->bloom_level);
            $this->assertNotNull($obj->confidence);
        }
    }

    public function test_standalone_tos_table_pdf_can_be_exported_without_exam_generation(): void
    {
        $tos = TableOfSpecification::create([
            'course' => 'Digital Circuits & Logic Design',
            'total_items' => 85,
            'distribution' => [
                'Remembering' => ['weight' => 0.20, 'item_count' => 17],
                'Understanding' => ['weight' => 0.20, 'item_count' => 17],
                'Applying' => ['weight' => 0.20, 'item_count' => 17],
                'Analyzing' => ['weight' => 0.15, 'item_count' => 13],
                'Evaluating' => ['weight' => 0.15, 'item_count' => 13],
                'Creating' => ['weight' => 0.10, 'item_count' => 8],
            ],
        ]);

        $lesson = $tos->lessons()->create([
            'title' => 'Common Number Systems and Conversions',
            'weight' => 5.0,
            'pdf_path' => 'lesson_pdfs/dummy.pdf',
            'item_quota' => 24,
            'level_distribution' => [
                'Remembering' => 8,
                'Understanding' => 16,
                'Applying' => 0,
                'Analyzing' => 0,
                'Evaluating' => 0,
                'Creating' => 0,
            ],
            'sort_order' => 0,
        ]);

        $lesson->objectives()->create([
            'tos_id' => $tos->id,
            'objective_text' => 'ILO 4. Define the number system;',
            'bloom_level' => 'Remembering',
            'bloom_level_index' => 0,
            'confidence' => 95.0,
        ]);

        // Exam questions have NOT been generated
        $this->assertFalse($tos->examQuestions()->exists());

        // Export PDF
        $response = $this->get(route('tos.export-pdf', $tos));

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
    }

    public function test_artisan_command_processes_inputs_and_prints_terminal_output_and_generates_pdf(): void
    {
        $tempPdf = storage_path('app/temp_test_input.pdf');
        File::put($tempPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF");

        $outputPath = storage_path('app/temp_test_tos_output.pdf');
        if (File::exists($outputPath)) {
            File::delete($outputPath);
        }

        $this->artisan('tos:process', [
            '--course' => 'Digital Logic Design',
            '--items' => 30,
            '--title' => 'Arithmetic Operations on Number Systems',
            '--weight' => 2,
            '--pdf' => $tempPdf,
            '--objectives' => "Explain the principles of Binary Addition & Subtraction\nUnderstand the binary codes",
            '--output' => $outputPath,
        ])
            ->expectsOutputToContain('TABLE OF SPECIFICATION (TOS) & BLOOM TAXONOMY CLASSIFICATION RESULT')
            ->expectsOutputToContain('CLASSIFIED INTENDED LEARNING OUTCOMES')
            ->expectsOutputToContain('Arithmetic Operations on Number Systems')
            ->expectsOutputToContain('TABLE OF SPECIFICATION (TOS) BREAKDOWN')
            ->assertExitCode(0);

        // Verify exported PDF was created on disk
        $this->assertTrue(File::exists($outputPath));
        $pdfBytes = File::get($outputPath);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);

        // Clean up temporary test files
        File::delete($tempPdf);
        File::delete($outputPath);
    }
}
