{{--
    CSS Paged Media for the printed Beleg (tech stack §7.1).

    WeasyPrint, not a browser, so this is print CSS and nothing else: no media
    queries, no viewport units, no flexbox gaps that depend on a layout engine
    Chrome would provide. What it does rely on are the three Paged Media
    features WeasyPrint implements well — @page margin boxes, running elements
    through position: running(), and counter(pages).

    Font stack names what the image actually installs. DejaVu Sans covers German
    completely; Liberation Sans is the metric-compatible Arial stand-in and the
    fallback. A generic sans-serif last, so a font that leaves the image
    degrades rather than renders empty boxes.
--}}
<style>
    @page {
        size: A4;
        /* Room at the foot for the identity block, which every German business
           letter carries and §35a GmbHG makes compulsory for a GmbH. */
        margin: 20mm 20mm 38mm 25mm;

        @bottom-left {
            content: element(identity);
            vertical-align: top;
        }
    }

    html {
        font-family: "DejaVu Sans", "Liberation Sans", sans-serif;
        font-size: 9.5pt;
        line-height: 1.45;
        color: #1a1a1a;
    }

    body {
        margin: 0;
    }

    /* The running footer. Taken out of the flow by position: running() and
       drawn into @bottom-left on every page, including the last. */
    .identity {
        position: running(identity);
        font-size: 7pt;
        line-height: 1.35;
        color: #555;
        border-top: 0.4pt solid #bbb;
        padding-top: 2mm;
        width: 100%;
    }

    .identity table {
        width: 100%;
        border-collapse: collapse;
    }

    /* No fixed column widths: the footer's four cells are sized by their
       content, because fixing them is what broke „HRB 123456" and
       „IBAN: DE02…" across lines — widening one column only moved the break to
       another. Two lines are declared unbreakable instead, and at 7pt the four
       columns come to about 156mm inside the 165mm the page margins leave. */
    .identity td {
        vertical-align: top;
        padding-right: 5mm;
    }

    /* A Registernummer split after „HRB", or an IBAN split after its label,
       reads as two facts rather than one. */
    .identity .register,
    .identity .iban {
        white-space: nowrap;
    }

    .identity .page-number {
        text-align: right;
        white-space: nowrap;
        padding-right: 0;
        width: 12%;
    }

    /* „Seite 1 von 2". The words stay in lang/de/document.php and only the two
       numbers come from CSS counters, which are the only way to know them —
       so the lang string's placeholders are swapped for counter() calls rather
       than the sentence being written twice, once here in German. */
    .identity .page-number::after {
        content: {!! $pageCounterContent !!};
    }

    /* The top block: logo right, sender line and recipient left. The German
       letter convention, and where a window envelope expects them. */
    .head {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8mm;
    }

    .head td {
        vertical-align: top;
    }

    .head .logo {
        text-align: right;
        width: 45%;
    }

    .head .logo img {
        max-height: 22mm;
        max-width: 70mm;
    }

    .sender {
        font-size: 6.5pt;
        color: #666;
        border-bottom: 0.4pt solid #ccc;
        padding-bottom: 1mm;
        margin-bottom: 3mm;
    }

    .recipient {
        /* 40mm down the page is where a DIN 5008 address window sits. */
        min-height: 27mm;
    }

    .recipient .name {
        font-weight: bold;
    }

    /* The Beleg's own particulars, as a definition list rather than a table:
       label left, value right, and the pairs stay together across a break. */
    .meta {
        border-collapse: collapse;
        margin-bottom: 7mm;
        width: 62%;
    }

    .meta th {
        text-align: left;
        font-weight: normal;
        color: #555;
        padding: 0.6mm 6mm 0.6mm 0;
        white-space: nowrap;
    }

    .meta td {
        text-align: left;
        padding: 0.6mm 0;
    }

    h1 {
        font-size: 15pt;
        margin: 0 0 1mm 0;
    }

    .subject {
        margin: 0 0 6mm 0;
        font-size: 9.5pt;
    }

    /* The Positionen. thead repeats on every page — WeasyPrint does this for a
       real <thead>, which is why the table is not a grid of divs. */
    table.positions {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4mm;
    }

    table.positions thead th {
        text-align: left;
        font-size: 8pt;
        text-transform: uppercase;
        letter-spacing: 0.3pt;
        color: #555;
        border-bottom: 0.6pt solid #888;
        padding: 1.5mm 2mm 1.5mm 0;
    }

    table.positions tbody td {
        vertical-align: top;
        padding: 1.8mm 2mm 1.8mm 0;
        border-bottom: 0.3pt solid #e0e0e0;
    }

    table.positions tbody tr {
        /* A Position must not be split down the middle of its Beschreibung. */
        page-break-inside: avoid;
    }

    .col-pos { width: 8%; }
    .col-title { width: 44%; }
    .col-quantity { width: 14%; text-align: right; }
    .col-price { width: 14%; text-align: right; }
    .col-rate { width: 8%; text-align: right; }
    .col-net { width: 16%; text-align: right; }

    td.col-quantity,
    td.col-price,
    td.col-rate,
    td.col-net,
    th.col-quantity,
    th.col-price,
    th.col-rate,
    th.col-net {
        text-align: right;
        padding-right: 0;
        white-space: nowrap;
    }

    .position-description {
        color: #555;
        font-size: 8.5pt;
        margin-top: 0.8mm;
        white-space: pre-line;
    }

    /* The sums. Right-aligned in a narrow block so the figures line up on
       their own column rather than against the Positionen above. */
    .sums {
        width: 52%;
        margin-left: 48%;
        border-collapse: collapse;
        page-break-inside: avoid;
    }

    .sums th {
        text-align: left;
        font-weight: normal;
        padding: 1mm 4mm 1mm 0;
    }

    .sums td {
        text-align: right;
        white-space: nowrap;
        padding: 1mm 0;
    }

    .sums tr.gross th,
    .sums tr.gross td {
        font-weight: bold;
        border-top: 0.6pt solid #888;
        padding-top: 1.6mm;
    }

    .note {
        margin-top: 5mm;
        font-size: 8.5pt;
    }

    .closing {
        margin-top: 7mm;
        page-break-inside: avoid;
    }

    .closing p {
        margin: 0 0 3mm 0;
    }
</style>
