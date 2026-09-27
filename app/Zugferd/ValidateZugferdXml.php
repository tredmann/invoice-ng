<?php

declare(strict_types=1);

namespace App\Zugferd;

use App\Exceptions\ZugferdValidationFailed;
use DOMDocument;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdSettings;
use LibXMLError;

/**
 * The runtime half of tech stack §10.2: XSD validation through libxml, inside
 * the issue transaction.
 *
 * It takes milliseconds and it guards the issue operation, which is why it runs
 * here and the official EN16931 **Schematron** does not — that one is a second
 * process over an XSLT 2.0 stylesheet, far too heavy to run while the
 * Nummernkreis is locked. The Schematron judges the golden fixtures in the test
 * suite instead; see `App\Zugferd\SchematronValidator`.
 *
 * What this catches is a structurally broken document: a missing mandatory
 * element, a value of the wrong type, children in the wrong order. What it does
 * not catch is a document that is well-formed and wrong — totals that do not
 * add up, a category code that contradicts its rate. Those are business rules,
 * and business rules are Schematron's job.
 *
 * **It takes the XML, not the builder.** horstoeko's own ZugferdXsdValidator
 * does the same libxml call, but only against a ZugferdDocument, whose
 * constructor is final — so nothing could hand it invalid XML and watch it
 * refuse. A validator that cannot be shown failing is decoration (CLAUDE.md).
 * The XSD path still comes from the library, which owns the schema files.
 */
final class ValidateZugferdXml
{
    public function __invoke(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if ($document->loadXML($xml) === false) {
                throw new ZugferdValidationFailed($this->errors());
            }

            if ($document->schemaValidate($this->schemaPath()) === false) {
                throw new ZugferdValidationFailed($this->errors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        return array_values(array_map(
            fn (LibXMLError $error): string => trim($error->message),
            libxml_get_errors(),
        ));
    }

    private function schemaPath(): string
    {
        $profile = ZugferdProfiles::PROFILEDEF[ZugferdProfiles::PROFILE_EN16931];

        return rtrim(ZugferdSettings::getSchemaDirectory(), '/').'/'.$profile['xsdfilename'];
    }
}
