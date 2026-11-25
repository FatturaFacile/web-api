<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Writes a multi-sheet .xlsx workbook with the OpenSpout writer: a bold frozen header row per sheet, amounts as numbers
 * and text neutralised against formula injection.
 */
final class WorkbookWriter
{
    /**
     * @param  list<array{name: string, headers: list<string>, rows: iterable<list<scalar|null>>}>  $sheets
     * @return string path of the temporary .xlsx file, to be deleted by the caller
     */
    public function write(array $sheets): string
    {
        $base = tempnam(sys_get_temp_dir(), 'bdg');

        if ($base === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the budget workbook.');
        }

        $path = $base.'.xlsx';
        rename($base, $path);

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $header = (new Style)->withFontBold(true);

            foreach ($sheets as $index => $sheet) {
                $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $current->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheet['name']) ?? 'Sheet', 0, 31));
                $current->setSheetView(new SheetView(freezeRow: 2));
                $writer->addRow(Row::fromValuesWithStyle($sheet['headers'], $header));

                foreach ($sheet['rows'] as $values) {
                    $writer->addRow(new Row(array_map(self::cell(...), $values)));
                }
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    private static function cell(mixed $value): Cell
    {
        if ($value === null || $value === '') {
            return new StringCell('', null);
        }

        if (is_int($value) || is_float($value)) {
            return new NumericCell($value);
        }

        return new StringCell(CsvFormulaNeutralizer::neutralize((string) $value), null);
    }
}
