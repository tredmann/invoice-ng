<?php

declare(strict_types=1);

namespace App\Zugferd;

use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdSettings;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The official EN16931 business rules — BR-*, BR-CO-*, BR-S-* — run over a
 * document and reported as a list of failures.
 *
 * System design §14 is blunt about why this exists: „Our opinion of the XML is
 * worth nothing; the recipient's software is the judge." The Schematron in
 * `horstoeko/zugferd` is the published one, not ours, so a document that passes
 * it has been judged rather than asserted about.
 *
 * **Test infrastructure, deliberately.** It is not called inside the issue
 * transaction: it is a second process over an XSLT 2.0 stylesheet, and the
 * Nummernkreis is locked for the whole of that transaction (tech stack §7.4).
 * The runtime gate is `ValidateZugferdXml`, which is libxml and milliseconds.
 *
 * It is also not the whole of §14's „official validator". The KoSIT validator
 * additionally checks PDF/A-3 conformance of the container, which nothing here
 * verifies; that remains a recorded gap in `CLAUDE.md`.
 */
final class SchematronValidator
{
    /**
     * @return list<string> One entry per failed assertion, empty when the
     *                      document satisfies every rule.
     */
    public function __invoke(string $xml): array
    {
        $xmlPath = $this->write($xml);

        try {
            $result = Process::timeout(120)->run([
                'python3',
                base_path('docker/validate-schematron.py'),
                $this->stylesheetPath(),
                $xmlPath,
            ]);

            // A transform that could not run must not read as a clean bill of
            // health. "No findings" and "could not tell" are different answers
            // and only one of them means the document is good.
            throw_if(
                $result->failed(),
                RuntimeException::class,
                'The Schematron transform failed: '.$result->errorOutput(),
            );

            return $this->failures($result->output());
        } finally {
            @unlink($xmlPath);
        }
    }

    /**
     * @return list<string>
     */
    private function failures(string $svrl): array
    {
        $found = preg_match_all(
            '#<svrl:failed-assert[^>]*>(.*?)</svrl:failed-assert>#s',
            $svrl,
            $matches,
        );

        if ($found === false || $found === 0) {
            return [];
        }

        return array_values(array_map(
            static fn (string $block): string => trim(preg_replace('/\s+/', ' ', strip_tags($block)) ?? ''),
            $matches[1],
        ));
    }

    private function write(string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zugferd').'.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    private function stylesheetPath(): string
    {
        $profile = ZugferdProfiles::PROFILEDEF[ZugferdProfiles::PROFILE_EN16931];

        return rtrim(ZugferdSettings::getXsltDirectory(), '/').'/'.$profile['xsltfilename'];
    }
}
