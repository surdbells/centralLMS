<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/competencies/import/template
 *
 * A blank Job Competency Profile workbook, laid out the way the importer
 * reads: a "Detailed Competences" sheet for the dictionary and a "Competence
 * Mapping" sheet for the job profiles, each with the header row and a worked
 * example. An organisation that has no JCPs of its own can fill this in and
 * upload it rather than reverse-engineering the format.
 */
final class CompetencyTemplateAction
{
    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        AuthenticationMiddleware::require($request)->mustHave(Permission::CompetencyManage);

        $path = tempnam(sys_get_temp_dir(), 'jcp_tpl_') . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);

        // --- Detailed Competences: the dictionary --------------------------
        $writer->getCurrentSheet()->setName('Detailed Competences');
        $writer->addRow(Row::fromValues(['[Function] Competencies']));
        $writer->addRow(Row::fromValues([
            '#', 'Competency', 'Definition',
            'Knowledge', 'Skill', 'Mastery',
        ]));
        $writer->addRow(Row::fromValues([
            '1',
            'Well Control',
            'Understands, interprets and applies well control principles.',
            'Knows the principles of well control.',
            'Applies them at the choke during a kick.',
            'Coaches others through a well control event.',
        ]));
        $writer->addRow(Row::fromValues([
            '2', 'Casing Design', 'Designs casing strings for the well.',
            'Knows the inputs to a casing design.',
            'Produces a casing design.',
            'Reviews and signs off casing designs.',
        ]));

        // --- Competence Mapping: the job profiles --------------------------
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Competence Mapping');
        $writer->addRow(Row::fromValues(['[FUNCTION] COMPETENCE FRAMEWORK']));
        $writer->addRow(Row::fromValues([
            '#', 'Competenc(ies)',
            'General/ Senior Management', 'Management', 'Supervisor/ Team Leader', 'Professional Staff',
        ]));
        // The letters are the required level: K, S or M. A blank cell means the
        // job does not need that competency. The row number ties back to the
        // dictionary sheet above.
        $writer->addRow(Row::fromValues(['1', 'Well Control', 'M', 'M', 'S', 'K']));
        $writer->addRow(Row::fromValues(['2', 'Casing Design', 'M', 'M', 'S', 'K']));

        $writer->close();

        $stream = fopen($path, 'rb');
        $body = $response->getBody();
        if ($stream !== false) {
            while (!feof($stream)) {
                $body->write((string) fread($stream, 8192));
            }
            fclose($stream);
        }
        @unlink($path);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="JCP-template.xlsx"');
    }
}
