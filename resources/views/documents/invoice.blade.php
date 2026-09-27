{{--
    The printed Rechnung.

    One house template for every company (system design §7); what differs is the
    logo and the identity block, substituted from the **Festschreibung** rather
    than read from the live models. That is what makes this page and the embedded
    XML describe the same two parties, and what makes a rename next year leave
    this document exactly as the customer received it.

    Everything it needs is on $document. The renderer runs inside the issue
    transaction from an in-memory document that has not been saved yet, so this
    view must never query the tenant, the session or the request.

    @var \App\Models\Document $document
    @var \App\Money\Totals $totals
    @var string|null $logo  A data: URI, or null
--}}
@php
    use App\Money\Euro;
    use App\Models\LineItem;
    use App\Models\TaxRate;

    $seller = $document->frozen_block->seller;
    $buyer = $document->frozen_block->buyer;

    // See the note in styles.blade.php: the sentence lives in lang/, the two
    // numbers can only come from CSS counters.
    // The longer needle first, deliberately: str_replace walks the array in
    // order, so replacing ':page' before ':pages' would rewrite the prefix of
    // the second and leave a stray 's' on the page — which is exactly what it
    // did until a rendered page was looked at.
    $pageCounterContent = str_replace(
        [':pages', ':page'],
        ['" counter(pages) "', '" counter(page) "'],
        '"'.__('document.pdf.page', ['page' => ':page', 'pages' => ':pages']).'"',
    );
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('document.pdf.title') }} {{ $document->number }}</title>
    @include('documents.styles')
</head>
<body>

{{-- Drawn into @bottom-left on every page. Declared first so WeasyPrint has it
     before the first page is laid out. --}}
<div class="identity">
    <table>
        <tr>
            <td class="seller">
                <strong>{{ $seller->legalName() }}</strong><br>
                {{ $seller->street }}<br>
                {{ $seller->postalCode }} {{ $seller->city }}
            </td>
            <td class="legal">
                @if ($seller->registerCourt !== null && $seller->registerNumber !== null)
                    <span class="register">{{ __('document.pdf.footer.register', ['court' => $seller->registerCourt, 'number' => $seller->registerNumber]) }}</span><br>
                @endif
                @if ($seller->managingDirectors !== null)
                    {{ __('document.pdf.footer.directors', ['names' => $seller->managingDirectors]) }}<br>
                @endif
                @if ($seller->taxNumber !== null)
                    {{ __('document.pdf.footer.tax_number', ['number' => $seller->taxNumber]) }}<br>
                @endif
                @if ($seller->vatId !== null)
                    {{ __('document.pdf.footer.vat_id', ['number' => $seller->vatId]) }}
                @endif
            </td>
            <td class="bank">
                @if ($seller->iban !== null)
                    {{ $seller->bankName }}<br>
                    <span class="iban">{{ __('document.pdf.footer.iban', ['iban' => $seller->iban]) }}</span><br>
                    @if ($seller->bic !== null)
                        {{ __('document.pdf.footer.bic', ['bic' => $seller->bic]) }}
                    @endif
                @endif
            </td>
            <td class="page-number"></td>
        </tr>
    </table>
</div>

<table class="head">
    <tr>
        <td>
            <div class="sender">
                {{ $seller->legalName() }} · {{ $seller->street }} · {{ $seller->postalCode }} {{ $seller->city }}
            </div>
            <div class="recipient">
                <span class="name">{{ $buyer->name }}</span><br>
                @if ($buyer->contactPerson !== null)
                    {{ $buyer->contactPerson }}<br>
                @endif
                {{ $buyer->street }}<br>
                {{ $buyer->postalCode }} {{ $buyer->city }}
            </div>
        </td>
        <td class="logo">
            @if ($logo !== null)
                <img src="{{ $logo }}" alt="">
            @endif
        </td>
    </tr>
</table>

<h1>{{ __('document.pdf.title') }} {{ $document->number }}</h1>

<table class="meta">
    <tr>
        <th>{{ __('document.pdf.number') }}</th>
        <td>{{ $document->number }}</td>
    </tr>
    <tr>
        <th>{{ __('document.pdf.issued_on') }}</th>
        <td>{{ $document->issued_on->format('d.m.Y') }}</td>
    </tr>
    <tr>
        <th>{{ __('document.pdf.customer_number') }}</th>
        <td>{{ $buyer->number }}</td>
    </tr>
    {{-- Exactly one of the two, never both: EN16931 maps them to BT-72 and
         BG-14 and the model guarantees only one is set. --}}
    @if ($document->performed_on !== null)
        <tr>
            <th>{{ __('document.pdf.performed_on') }}</th>
            <td>{{ $document->performed_on->format('d.m.Y') }}</td>
        </tr>
    @elseif ($document->performed_from !== null && $document->performed_to !== null)
        <tr>
            <th>{{ __('document.pdf.performed_period') }}</th>
            <td>{{ __('document.pdf.period', [
                'from' => $document->performed_from->format('d.m.Y'),
                'to' => $document->performed_to->format('d.m.Y'),
            ]) }}</td>
        </tr>
    @endif
    <tr>
        <th>{{ __('document.pdf.due_on') }}</th>
        <td>{{ $document->due_on->format('d.m.Y') }}</td>
    </tr>
</table>

<p class="subject">{{ __('document.pdf.salutation') }}<br>{{ __('document.pdf.intro') }}</p>

<table class="positions">
    <thead>
        <tr>
            <th class="col-pos">{{ __('document.pdf.position') }}</th>
            <th class="col-title">{{ __('document.pdf.description') }}</th>
            <th class="col-quantity">{{ __('document.pdf.quantity') }}</th>
            <th class="col-price">{{ __('document.pdf.unit_price') }}</th>
            @unless ($seller->isSmallBusiness())
                <th class="col-rate">{{ __('document.pdf.tax_rate') }}</th>
            @endunless
            <th class="col-net">{{ __('document.pdf.line_net') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->lineItems as $item)
            <tr>
                <td class="col-pos">{{ $item->position }}</td>
                <td class="col-title">
                    {{ $item->title }}
                    @if ($item->description !== null)
                        <div class="position-description">{{ $item->description }}</div>
                    @endif
                </td>
                <td class="col-quantity">{{ LineItem::formatQuantity((string) $item->quantity) }} {{ $item->unit->getLabel() }}</td>
                <td class="col-price">{{ Euro::format($item->unit_price) }}</td>
                @unless ($seller->isSmallBusiness())
                    <td class="col-rate">{{ TaxRate::formatRate($item->tax_rate) }}</td>
                @endunless
                <td class="col-net">{{ Euro::format($item->net()) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="sums">
    <tr>
        <th>{{ __('document.pdf.net') }}</th>
        <td>{{ Euro::format($totals->net) }}</td>
    </tr>
    {{-- One row per Steuersatz, highest first, exactly as CalculateTotals
         grouped them: §6 rounds once per group, so these are the figures the
         recipient's accounting software will arrive at too. A Kleinunternehmer
         has one group at 0 % and no USt to show. --}}
    @unless ($seller->isSmallBusiness())
        @foreach ($totals->groups as $group)
            <tr>
                <th>{{ __('document.pdf.tax_group', [
                    'rate' => TaxRate::formatRate($group->rate),
                    'base' => Euro::format($group->base),
                ]) }}</th>
                <td>{{ Euro::format($group->tax) }}</td>
            </tr>
        @endforeach
    @endunless
    <tr class="gross">
        <th>{{ __('document.pdf.gross') }}</th>
        <td>{{ Euro::format($totals->gross) }}</td>
    </tr>
</table>

@if ($seller->isSmallBusiness())
    <p class="note">{{ __('document.pdf.small_business_note') }}</p>
@endif

<div class="closing">
    <p>
        @if ($document->payment_term->days() === 0)
            {{ __('document.pdf.closing_immediate') }}
        @else
            {{ __('document.pdf.closing', ['date' => $document->due_on->format('d.m.Y')]) }}
        @endif
    </p>
    <p>{{ __('document.pdf.regards') }}<br>{{ $seller->legalName() }}</p>
</div>

</body>
</html>
