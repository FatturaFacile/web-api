<?php

declare(strict_types=1);

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\CvParseStatus;
use Modules\HumanResources\Services\NaiveTextCvParser;

function cvPdfBytes(array $lines): string
{
    $stream = "BT /F1 12 Tf 72 720 Td\n";
    foreach ($lines as $line) {
        $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj 0 -16 Td\n";
    }
    $stream .= 'ET';

    return "%PDF-1.4\n4 0 obj << /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF";
}

test('plain text CVs yield contact data, skills, languages, education and experience', function () {
    $parser = new NaiveTextCvParser;

    $result = $parser->parseText(implode("\n", [
        'Luca Rossi',
        'luca.rossi@example.ch | +41 79 555 12 34',
        'Esperienza professionale',
        'Banca del Ceresio, Lugano 2018 - 2023',
        'Formazione',
        'SUPSI Manno 2014 - 2017',
        'Competenze',
        'Laravel, TypeScript, SQL and Kubernetes; JavaScript but not Java alone? Java too.',
        'Lingue',
        'Italiano madrelingua; Deutsch B2; English C1',
    ]));

    expect($result->status)->toBe(CvParseStatus::Parsed)
        ->and($result->email)->toBe('luca.rossi@example.ch')
        ->and($result->phone)->toBe('+41 79 555 12 34')
        ->and($result->skills)->toContain('Laravel', 'TypeScript', 'SQL', 'Kubernetes', 'JavaScript', 'Java')
        ->and($result->skills)->not->toContain('PHP')
        ->and($result->profile['experience'][0]['company'])->toBe('Banca del Ceresio, Lugano 2018 - 2023')
        ->and($result->profile['experience'][0]['period'])->toBe('2018 - 2023')
        ->and($result->profile['education'][0]['period'])->toBe('2014 - 2017')
        ->and($result->profile['languages'])->toContain(
            ['language' => 'Italian', 'level' => 'native'],
            ['language' => 'German', 'level' => 'B2'],
            ['language' => 'English', 'level' => 'C1'],
        );
});

test('uncompressed and Flate-compressed PDF text streams are read without external binaries', function () {
    config(['recruiting.pdftotext_binary' => '']);
    $parser = new NaiveTextCvParser;

    $plain = $parser->parse(cvPdfBytes(['Anna Bianchi', 'anna@example.com', 'Skills', 'PHP (Laravel), Docker']), 'application/pdf', 'cv.pdf');
    expect($plain->status)->toBe(CvParseStatus::Parsed)
        ->and($plain->email)->toBe('anna@example.com')
        ->and($plain->skills)->toContain('PHP', 'Laravel', 'Docker');

    $stream = gzcompress("BT (Sales and Marketing manager) Tj ET");
    $compressed = "%PDF-1.4\n4 0 obj << /Length ".strlen($stream)." /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF";
    expect($parser->parse($compressed, 'application/pdf', 'cv.pdf')->skills)->toContain('Sales', 'Marketing');
});

test('unreadable documents are reported as failed and never throw', function () {
    config(['recruiting.pdftotext_binary' => '']);
    $parser = new NaiveTextCvParser;

    expect($parser->parse(str_repeat("\0", 64), 'application/pdf', 'scan.pdf')->status)->toBe(CvParseStatus::Failed)
        ->and($parser->parse('not a zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'cv.docx')->status)->toBe(CvParseStatus::Failed)
        ->and($parser->parse('Just a name', 'text/plain', 'cv.txt')->status)->toBe(CvParseStatus::Partial);
});

test('docx CVs are read from word/document.xml', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ext-zip is not available.');
    }

    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Mario Neri</w:t></w:r></w:p><w:p><w:r><w:t>Skills: SAP &amp; Excel</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    $result = (new NaiveTextCvParser)->parse((string) file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'cv.docx');
    @unlink($path);

    expect($result->status)->toBe(CvParseStatus::Parsed)
        ->and($result->skills)->toContain('SAP', 'Excel');
});

test('stage transition table matches the REST contract', function () {
    expect(ApplicationStage::Applied->canTransitionTo(ApplicationStage::ManagerInterview))->toBeTrue()
        ->and(ApplicationStage::OfferExtended->canTransitionTo(ApplicationStage::HrScreening))->toBeTrue()
        ->and(ApplicationStage::TechnicalInterview->canTransitionTo(ApplicationStage::Rejected))->toBeTrue()
        ->and(ApplicationStage::Applied->canTransitionTo(ApplicationStage::Hired))->toBeFalse()
        ->and(ApplicationStage::Hired->canTransitionTo(ApplicationStage::Applied))->toBeFalse()
        ->and(ApplicationStage::Rejected->canTransitionTo(ApplicationStage::Applied))->toBeTrue()
        ->and(ApplicationStage::Rejected->canTransitionTo(ApplicationStage::HrScreening))->toBeFalse()
        ->and(ApplicationStage::Applied->canBeDisabled())->toBeFalse()
        ->and(ApplicationStage::HrScreening->canBeDisabled())->toBeTrue();
});
