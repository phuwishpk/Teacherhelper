<?php

namespace App\Domain\Exams;

/**
 * The pages of one version's question booklet (DESIGN §22.6 item 1): each
 * page lists its blocks (the cover header, then one block per question,
 * with the section heading kept on the block of the section's first
 * question) at the y where ExamBooklet measured it fits whole.
 */
final readonly class ExamBookletPlan
{
    /**
     * @param  list<list<array{top: float, height: float, html: string, numbers: list<int>}>>  $pages
     * @param  string|null  $versionLabel  null for an exam with one version
     */
    public function __construct(public array $pages, public ?string $versionLabel) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** "หน้า x/y ชุด ข" at the bottom of every page (the version code keeps booklets apart). */
    public function footer(int $page): string
    {
        return 'หน้า '.$page.'/'.$this->pageCount().($this->versionLabel !== null ? '   ชุด '.$this->versionLabel : '');
    }

    /**
     * Page (1-based) of each question number.
     *
     * @return array<int, int>
     */
    public function pageOfNumbers(): array
    {
        $out = [];
        foreach ($this->pages as $index => $blocks) {
            foreach ($blocks as $block) {
                foreach ($block['numbers'] as $number) {
                    $out[$number] = $index + 1;
                }
            }
        }

        return $out;
    }
}
