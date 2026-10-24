<?php

namespace App\Services\Bloom;

use App\Models\TableOfSpecification;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TosConsolePrinter
{
    private const LEVELS = [
        'Remembering',
        'Understanding',
        'Applying',
        'Analyzing',
        'Evaluating',
        'Creating',
    ];

    /**
     * Print the TOS and classified objectives results to terminal stdout.
     */
    public function print(TableOfSpecification $tos, ?OutputInterface $output = null, ?string $pdfPathOrUrl = null): void
    {
        $output ??= new ConsoleOutput;

        $tos->loadMissing('lessons.objectives');

        $totalHours = $tos->lessons->sum('weight') ?: 1;
        $totalItems = $tos->total_items;

        $output->writeln('');
        $output->writeln('<info>================================================================================</info>');
        $output->writeln('<info>       TABLE OF SPECIFICATION (TOS) & BLOOM TAXONOMY CLASSIFICATION RESULT     </info>');
        $output->writeln('<info>================================================================================</info>');
        $output->writeln(sprintf('<comment>Course:</comment> %s', $tos->course));
        $output->writeln(sprintf('<comment>Total Exam Items:</comment> %d', $totalItems));
        $output->writeln(sprintf('<comment>Total Instructional Time:</comment> %s hours', rtrim(rtrim(number_format($totalHours, 2), '0'), '.')));
        $output->writeln(sprintf('<comment>Total Lessons / Topics:</comment> %d', $tos->lessons->count()));
        $output->writeln('');

        // 1. Classified Objectives
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');
        $output->writeln('<info> CLASSIFIED INTENDED LEARNING OUTCOMES (ILO)</info>');
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');

        foreach ($tos->lessons as $lessonIndex => $lesson) {
            $output->writeln(sprintf(
                '<comment>[Lesson %d]</comment> <options=bold>%s</> (Time: %s hrs, Items: %d)',
                $lessonIndex + 1,
                $lesson->title,
                rtrim(rtrim(number_format($lesson->weight, 2), '0'), '.'),
                $lesson->item_quota
            ));

            if ($lesson->objectives->isEmpty()) {
                $output->writeln('   <fg=gray>(No objectives parsed for this lesson)</>');
            } else {
                foreach ($lesson->objectives as $objIndex => $obj) {
                    $conf = $obj->confidence !== null ? sprintf(' (%.1f%% conf)', (float) $obj->confidence) : '';
                    $output->writeln(sprintf(
                        '   <fg=cyan>[ILO %d]</> %s',
                        $objIndex + 1,
                        $obj->objective_text
                    ));
                    $output->writeln(sprintf(
                        '          <fg=green>-> Bloom Level: %s%s</>',
                        $obj->bloom_level,
                        $conf
                    ));
                }
            }
            $output->writeln('');
        }

        // 2. Table of Specification Grid
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');
        $output->writeln('<info> TABLE OF SPECIFICATION (TOS) BREAKDOWN</info>');
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');

        $table = new Table($output);
        $table->setHeaders([
            'Topic / Lesson',
            'Hours',
            'Rem',
            'Und',
            'App',
            'Ana',
            'Eva',
            'Cre',
            'Items',
            '% Dist',
        ]);

        $rows = [];
        $levelTotals = array_fill_keys(self::LEVELS, 0);

        foreach ($tos->lessons as $lesson) {
            $rowLevels = [];
            foreach (self::LEVELS as $lvl) {
                $count = (int) ($lesson->level_distribution[$lvl] ?? 0);
                $rowLevels[] = $count > 0 ? (string) $count : '-';
                $levelTotals[$lvl] += $count;
            }

            $percent = number_format(($lesson->weight / $totalHours) * 100, 1).'%';

            $rows[] = array_merge(
                [
                    $lesson->title,
                    rtrim(rtrim(number_format($lesson->weight, 2), '0'), '.'),
                ],
                $rowLevels,
                [
                    (string) $lesson->item_quota,
                    $percent,
                ]
            );
        }

        $rows[] = new TableSeparator;

        $totalLevelCells = [];
        foreach (self::LEVELS as $lvl) {
            $totalCount = $tos->distribution[$lvl]['item_count'] ?? $levelTotals[$lvl];
            $totalLevelCells[] = $totalCount > 0 ? (string) $totalCount : '-';
        }

        $rows[] = array_merge(
            [
                'TOTAL',
                rtrim(rtrim(number_format($totalHours, 2), '0'), '.'),
            ],
            $totalLevelCells,
            [
                (string) $totalItems,
                '100%',
            ]
        );

        $table->setRows($rows);
        $table->render();

        $output->writeln('');
        if ($pdfPathOrUrl) {
            $output->writeln(sprintf('<info>TOS Table PDF:</info> <comment>%s</comment>', $pdfPathOrUrl));
        } else {
            $output->writeln(sprintf('<info>TOS Export Route:</info> <comment>/tos/%s/export-pdf</comment>', $tos->uuid));
        }
        $output->writeln('<info>================================================================================</info>');
        $output->writeln('');
    }
}
