#!/usr/bin/env python3
"""Run a compiled Schematron stylesheet over an XML file and print the SVRL.

Why this exists as a Python script rather than PHP: horstoeko/zugferd ships the
official EN16931 Schematron compiled to XSLT, but that stylesheet is XSLT 2.0
and PHP's libxslt implements 1.0 only, so XSLTProcessor cannot run it. SaxonC-HE
is an XSLT 3.0 processor with no JVM, which is what keeps the KoSIT validator
(Java) out of this image.

It is test infrastructure. Nothing calls it inside the issue transaction — the
runtime gate is XSD validation through libxml (tech stack §10.2).

Usage: validate-schematron.py <stylesheet.xslt> <document.xml>
Prints the SVRL report on stdout; exits non-zero only when the transform itself
fails, not when the document has findings. Reading the findings is the caller's
job, because "no findings" and "could not tell" must not look the same.
"""

import sys

from saxonche import PySaxonProcessor


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print(f"usage: {argv[0]} <stylesheet.xslt> <document.xml>", file=sys.stderr)
        return 2

    stylesheet, document = argv[1], argv[2]

    with PySaxonProcessor(license=False) as processor:
        xslt = processor.new_xslt30_processor()
        executable = xslt.compile_stylesheet(stylesheet_file=stylesheet)

        if executable is None:
            print(f"could not compile {stylesheet}", file=sys.stderr)
            return 1

        report = executable.transform_to_string(source_file=document)

        if report is None:
            print(f"transform produced nothing for {document}", file=sys.stderr)
            return 1

        print(report)

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
